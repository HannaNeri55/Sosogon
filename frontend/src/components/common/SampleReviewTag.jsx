// Tags a seeded demo review (see parseReview) so it can't pass as real visitor feedback.
export default function SampleReviewTag() {
  return (
    <span
      className="ml-2 inline-block rounded-full bg-amber-50 px-2 py-0.5 align-middle text-xs font-medium text-amber-800"
      title="Sample review added for demonstration, not from a real visitor"
    >
      Sample review
    </span>
  )
}
