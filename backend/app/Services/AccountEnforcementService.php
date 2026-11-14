<?php

namespace App\Services;

use App\Models\AccountActionLog;
use App\Models\AccountAppeal;
use App\Models\AccountViolation;
use App\Models\User;
use App\Notifications\AccountStatusNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Every account enforcement change goes through here: warnings, temporary suspensions,
 * deactivations, reactivation, automatic expiry, account closure and appeal decisions.
 * Each one updates the account, keeps the violation record, writes the audit log and
 * notifies the affected user. Nothing here deletes user data.
 */
class AccountEnforcementService
{
    /** Private disk for evidence and appeal attachments (served only through authorized endpoints). */
    public const FILES_DISK = 'local';

    // ==================== ADMIN ACTIONS ====================

    /**
     * Warn, temporarily suspend or deactivate an account.
     *
     * @param  array{category: string, reason: string, details: string, evidence_notes?: ?string, action: string, suspension_days?: ?int, review_date?: ?string, reported_at?: ?string}  $data
     * @param  UploadedFile[]  $evidence
     */
    public function enforce(User $target, User $admin, array $data, array $evidence = [], ?string $ip = null): AccountViolation
    {
        $this->guardTarget($target, $admin);

        $stored = $this->storeFiles($evidence, "account-evidence/{$target->id}");

        try {
            $violation = DB::transaction(function () use ($target, $admin, $data, $stored, $ip) {
                $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $previous = $target->accountState();
                $action = $data['action'];
                $now = now();

                $endsAt = match ($action) {
                    AccountViolation::ACTION_SUSPENSION => $now->copy()->addDays((int) $data['suspension_days']),
                    AccountViolation::ACTION_WARNING => ! empty($data['review_date'])
                        ? \Illuminate\Support\Carbon::parse($data['review_date'])->endOfDay()
                        : $now->copy()->addDays(AccountViolation::WARNING_DAYS),
                    default => null,
                };

                $violation = AccountViolation::create([
                    'user_id' => $target->id,
                    // The review this is about, if any (see App\Support\AdminScope::managesUser).
                    'rating_id' => $data['rating_id'] ?? null,
                    'reviewed_by' => $admin->id,
                    'category' => $data['category'],
                    'reason' => $data['reason'],
                    'details' => $data['details'],
                    'evidence_notes' => $data['evidence_notes'] ?? null,
                    'evidence_files' => $stored ?: null,
                    'action' => $action,
                    'suspension_days' => $action === AccountViolation::ACTION_SUSPENSION ? (int) $data['suspension_days'] : null,
                    'starts_at' => $now,
                    'ends_at' => $endsAt,
                    'review_date' => $data['review_date'] ?? null,
                    'reported_at' => $data['reported_at'] ?? $now->toDateString(),
                    'reviewed_at' => $now,
                    'status' => AccountViolation::STATUS_ACTIVE,
                ]);

                if ($action !== AccountViolation::ACTION_WARNING) {
                    $this->syncRestriction($target);
                    // Signed-in sessions end now; the user can sign in again only to see their status and appeal.
                    $target->tokens()->delete();
                }

                $this->log($admin, $target, "issued_{$action}", $previous, $target->accountState(), $data['reason'], [
                    'violation_id' => $violation->id,
                    'category' => $data['category'],
                    'suspension_days' => $violation->suspension_days,
                    'ends_at' => $violation->ends_at?->toIso8601String(),
                ], $ip);

                return $violation;
            });
        } catch (\Throwable $e) {
            $this->deleteFiles($stored);
            throw $e;
        }

        $this->notify($target, $this->enforcementNotice($violation));

        return $violation;
    }

    /** Lift a suspension or deactivation early (an admin decision, with a reason). */
    public function reactivate(User $target, User $admin, string $reason, ?string $ip = null): void
    {
        $this->guardTarget($target, $admin);

        DB::transaction(function () use ($target, $admin, $reason, $ip) {
            $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $previous = $target->accountState();
            abort_unless(in_array($previous, [User::STATE_SUSPENDED, User::STATE_DEACTIVATED], true), 409, 'Only a suspended or deactivated account can be reactivated.');

            $target->violations()
                ->whereIn('action', [AccountViolation::ACTION_SUSPENSION, AccountViolation::ACTION_DEACTIVATION])
                ->where('status', AccountViolation::STATUS_ACTIVE)
                ->update([
                    'status' => AccountViolation::STATUS_LIFTED,
                    'resolved_at' => now(),
                    'resolved_by' => $admin->id,
                    'resolution_note' => $reason,
                ]);
            $this->syncRestriction($target);

            $this->log($admin, $target, 'reactivated', $previous, $target->accountState(), $reason, null, $ip);
        });

        $this->notify($target, new AccountStatusNotification(
            'reactivation',
            'Your account has been reactivated',
            'The Sorsogon Tourism Office has restored full access to your SOSOGON account.',
            $reason,
            'You can sign in and use all features again. Your earlier violation records stay on your Account Health page for reference.',
        ));
    }

    /**
     * Close an account (the separate "delete account" process). Sign-in stops, but nothing is
     * erased: profile, listings, reviews and every enforcement record are kept.
     */
    public function close(User $target, User $admin, string $reason, ?string $ip = null): void
    {
        $this->guardTarget($target, $admin);
        abort_if((bool) $target->closed_at, 409, 'This account is already closed.');

        DB::transaction(function () use ($target, $admin, $reason, $ip) {
            $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $previous = $target->accountState();
            $target->forceFill(['closed_at' => now()])->save();
            $target->tokens()->delete();
            $this->log($admin, $target, 'closed_account', $previous, User::STATE_CLOSED, $reason, null, $ip);
        });

        $this->notify($target, new AccountStatusNotification(
            'closure',
            'Your account has been closed',
            'The Sorsogon Tourism Office has closed your SOSOGON account. You can no longer sign in.',
            $reason,
            'Your information has not been erased. If you believe this is a mistake, contact the Sorsogon Tourism Office.',
        ));
    }

    // ==================== AUTOMATIC EXPIRY ====================

    /** A temporary suspension has ended: restore access unless another restriction is still in force. */
    public function expireSuspension(User $user): bool
    {
        $restored = DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $user || $user->status !== 'suspended' || ! $user->suspended_until || $user->suspended_until->isFuture() || $user->closed_at) {
                return null;
            }
            $previous = User::STATE_SUSPENDED;

            $user->violations()
                ->where('action', AccountViolation::ACTION_SUSPENSION)
                ->where('status', AccountViolation::STATUS_ACTIVE)
                ->where('ends_at', '<=', now())
                ->update(['status' => AccountViolation::STATUS_EXPIRED, 'resolved_at' => now()]);
            $this->syncRestriction($user);

            $this->log(null, $user, 'suspension_expired', $previous, $user->accountState(), 'The suspension period ended.');

            return $user->isRestricted() ? null : $user;
        });

        if ($restored) {
            $this->notify($restored, new AccountStatusNotification(
                'suspension_ended',
                'Your suspension has ended',
                'Your temporary suspension is over and full access to your SOSOGON account has been restored.',
                null,
                'Please keep following the platform rules. Your violation history remains on your Account Health page.',
            ));
        }

        return (bool) $restored;
    }

    // ==================== APPEALS ====================

    /** @param  UploadedFile[]  $attachments */
    public function submitAppeal(User $user, AccountViolation $violation, string $explanation, array $attachments = []): AccountAppeal
    {
        abort_unless($violation->user_id === $user->id, 404);
        abort_if($violation->status === AccountViolation::STATUS_REVERSED, 409, 'This decision has already been reversed.');
        abort_if($violation->appeals()->exists(), 409, 'You have already appealed this decision.');

        $stored = $this->storeFiles($attachments, "account-appeals/{$user->id}");

        try {
            $appeal = DB::transaction(function () use ($user, $violation, $explanation, $stored) {
                $appeal = AccountAppeal::create([
                    'account_violation_id' => $violation->id,
                    'user_id' => $user->id,
                    'explanation' => $explanation,
                    'attachments' => $stored ?: null,
                    'status' => AccountAppeal::STATUS_PENDING,
                ]);
                $this->log(null, $user, 'appeal_submitted', $user->accountState(), $user->accountState(), null, [
                    'appeal_id' => $appeal->id,
                    'violation_id' => $violation->id,
                ]);

                return $appeal;
            });
        } catch (\Throwable $e) {
            $this->deleteFiles($stored);
            throw $e;
        }

        return $appeal;
    }

    /** Approve (reverse the action, restore access if nothing else applies) or reject an appeal. */
    public function decideAppeal(AccountAppeal $appeal, User $admin, string $decision, string $note, ?string $ip = null): AccountAppeal
    {
        $appeal = DB::transaction(function () use ($appeal, $admin, $decision, $note, $ip) {
            $appeal = AccountAppeal::whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            abort_if($appeal->status !== AccountAppeal::STATUS_PENDING, 409, 'This appeal has already been decided.');

            $user = User::whereKey($appeal->user_id)->lockForUpdate()->firstOrFail();
            $previous = $user->accountState();

            $appeal->forceFill([
                'status' => $decision,
                'decision_note' => $note,
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ])->save();

            if ($decision === AccountAppeal::STATUS_APPROVED) {
                $appeal->violation->forceFill([
                    'status' => AccountViolation::STATUS_REVERSED,
                    'resolved_at' => now(),
                    'resolved_by' => $admin->id,
                    'resolution_note' => 'Appeal approved: '.$note,
                ])->save();
                if (! $user->closed_at) {
                    $this->syncRestriction($user);
                }
            }

            $this->log($admin, $user, "appeal_{$decision}", $previous, $user->accountState(), $note, [
                'appeal_id' => $appeal->id,
                'violation_id' => $appeal->account_violation_id,
            ], $ip);

            return $appeal;
        });

        $approved = $decision === AccountAppeal::STATUS_APPROVED;
        $this->notify($appeal->user, new AccountStatusNotification(
            $approved ? 'appeal_approved' : 'appeal_rejected',
            $approved ? 'Your appeal was approved' : 'Your appeal was not approved',
            $approved
                ? 'After review, the Sorsogon Tourism Office has reversed the enforcement action you appealed and corrected your violation record.'
                : 'After review, the Sorsogon Tourism Office has kept the original enforcement decision.',
            $note,
            $approved
                ? ($appeal->user->fresh()->isRestricted() ? 'Another restriction is still in effect on your account; see your Account Health page.' : 'Your account access has been restored.')
                : 'This decision is final for this violation. See your Account Health page for details.',
            $appeal->account_violation_id,
        ));

        return $appeal->fresh(['violation', 'decider:id,name']);
    }

    // ==================== HELPERS ====================

    /**
     * Recompute status/suspended_until from the violations still in force: any current
     * deactivation means deactivated; otherwise the latest-ending current suspension; otherwise active.
     * Pending (registration review) and closed accounts are left alone.
     */
    public function syncRestriction(User $user): void
    {
        if ($user->closed_at || ($user->status === 'pending')) {
            return;
        }

        $current = $user->violations()
            ->whereIn('action', [AccountViolation::ACTION_SUSPENSION, AccountViolation::ACTION_DEACTIVATION])
            ->current()
            ->get(['action', 'ends_at']);

        if ($current->contains('action', AccountViolation::ACTION_DEACTIVATION)) {
            $user->forceFill(['status' => 'suspended', 'suspended_until' => null])->save();
        } elseif ($current->isNotEmpty()) {
            $user->forceFill(['status' => 'suspended', 'suspended_until' => $current->max('ends_at')])->save();
        } else {
            $user->forceFill(['status' => 'active', 'suspended_until' => null])->save();
        }
    }

    /** Admins can't be actioned here, nor can an admin action their own account. */
    private function guardTarget(User $target, User $admin): void
    {
        if ($target->id === $admin->id) {
            throw new HttpException(403, 'You cannot take enforcement action on your own account.');
        }
        if ($target->isAdmin()) {
            throw new HttpException(403, 'Administrator accounts are protected from enforcement actions.');
        }
        if ($target->closed_at) {
            throw new HttpException(409, 'This account is closed.');
        }
    }

    public function log(?User $admin, User $user, string $action, ?string $previous, ?string $new, ?string $reason, ?array $context = null, ?string $ip = null): void
    {
        AccountActionLog::create([
            'admin_id' => $admin?->id,
            'admin_name' => $admin?->name ?? 'System',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'action' => $action,
            'previous_status' => $previous,
            'new_status' => $new,
            'reason' => $reason,
            'context' => $context,
            'ip_address' => $ip,
        ]);
    }

    private function enforcementNotice(AccountViolation $violation): AccountStatusNotification
    {
        $appeal = 'If you believe this decision is wrong, you can submit an appeal from your Account Health page.';

        return match ($violation->action) {
            AccountViolation::ACTION_WARNING => new AccountStatusNotification(
                'warning',
                'You have received an official warning',
                "The Sorsogon Tourism Office has issued a warning on your account for: {$violation->category_label}. Your account remains active.",
                $violation->reason,
                'Please review the platform rules to avoid further action. '.$appeal,
                $violation->id,
            ),
            AccountViolation::ACTION_SUSPENSION => new AccountStatusNotification(
                'suspension',
                "Your account is suspended for {$violation->suspension_days} day".($violation->suspension_days === 1 ? '' : 's'),
                "Your SOSOGON account has been temporarily suspended for: {$violation->category_label}. Until {$violation->ends_at->timezone('Asia/Manila')->format('F j, Y g:i A')} (PH time) you can only view your account status and submit an appeal.",
                $violation->reason,
                'Access is restored automatically when the suspension ends. '.$appeal,
                $violation->id,
                $violation->ends_at->toIso8601String(),
            ),
            default => new AccountStatusNotification(
                'deactivation',
                'Your account has been deactivated',
                "Your SOSOGON account has been deactivated pending review for: {$violation->category_label}. You can only view your account status and submit an appeal.",
                $violation->reason,
                ($violation->review_date ? "The case is scheduled for review on {$violation->review_date->format('F j, Y')}. " : '').$appeal,
                $violation->id,
            ),
        };
    }

    /** In-app notice first, then email. Mail problems are logged and never undo the enforcement itself. */
    private function notify(User $user, AccountStatusNotification $notification): void
    {
        $user->notifyNow($notification, ['database']);

        try {
            $user->notifyNow($notification, ['mail']);
        } catch (\Throwable $e) {
            Log::error('Account notification email failed', ['user_id' => $user->id, 'kind' => $notification->kind, 'error' => $e->getMessage()]);
        }
    }

    /** @param  UploadedFile[]  $files */
    private function storeFiles(array $files, string $directory): array
    {
        $stored = [];
        foreach ($files as $file) {
            $stored[] = [
                'path' => $file->store($directory, self::FILES_DISK),
                'name' => mb_substr($file->getClientOriginalName(), 0, 150),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ];
        }

        return $stored;
    }

    private function deleteFiles(array $stored): void
    {
        $paths = array_filter(array_column($stored, 'path'));
        if ($paths) {
            Storage::disk(self::FILES_DISK)->delete($paths);
        }
    }
}
