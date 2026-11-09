import usePageTitle from "../../hooks/usePageTitle";
import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { FiFlag, FiStar, FiSun, FiClock, FiUsers, FiTag, FiMapPin } from "react-icons/fi";
import { getActivity } from "../../api/activities";
import LoadingSpinner from "../../components/common/LoadingSpinner";
import PageHeader from "../../components/layout/PageHeader";
import DetailLayout from "../../components/detail/DetailLayout";
import DetailSection from "../../components/detail/DetailSection";
import FactsCard from "../../components/detail/FactsCard";
import { getImageUrl } from "../../utils/helpers";
import LoadError from "../../components/common/LoadError";

export default function ActivityDetail() {
  const { id } = useParams();
  const [activity, setActivity] = useState(null);
  usePageTitle(activity?.name);
  const [loading, setLoading] = useState(true);
  // True when the request failed for a reason other than "not found"; attempt re-runs the load.
  const [loadFailed, setLoadFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true);
    getActivity(id)
      .then((data) => {
        setActivity(data);
        setLoadFailed(false);
      })
      .catch((error) => {
        setActivity(null);
        setLoadFailed(error.response?.status !== 404);
      })
      .finally(() => setLoading(false));
  }, [id, attempt]);

  const retry = () => setAttempt((n) => n + 1);

  if (loading) return <LoadingSpinner size="lg" />;
  if (!activity && loadFailed) return <div className="px-4 py-12 sm:py-16"><LoadError what="this activity" onRetry={retry} className="max-w-xl mx-auto" /></div>;
  if (!activity)
    return <div className="p-6 sm:p-8 text-center text-gray-500">Activity not found.</div>;

  const thingsToDo =
    activity.things_to_do
      ?.split("\n")
      .map((s) => s.trim())
      .filter(Boolean) || [];

  const price =
    activity.price === null || activity.price === undefined
      ? null
      : Number(activity.price) === 0
        ? "Free"
        : `₱${Number(activity.price).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-6xl mx-auto px-4 sm:px-6">
        <PageHeader
          back={
            activity.destination_id
              ? {
                  to: `/destinations/${activity.destination_id}`,
                  label: activity.destination?.name ? `Back to ${activity.destination.name}` : "Back to Destination",
                }
              : { to: "/destinations", label: "Back to Destinations" }
          }
          eyebrow={
            <span className="inline-block text-xs font-semibold uppercase tracking-wider text-secondary-700 bg-secondary-50 border border-secondary-100 px-3 py-1 rounded-full">
              Activity
            </span>
          }
          title={activity.name}
          subtitle={
            activity.destination?.name && (
              <span className="flex items-center gap-1.5">
                <FiMapPin size={15} className="text-primary-600" aria-hidden="true" /> {activity.destination.name}
              </span>
            )
          }
        />
        <div className="aspect-video max-h-128 w-full rounded-2xl overflow-hidden mb-8 bg-gray-200">
          <img src={getImageUrl(activity.image_path)} alt={activity.name} className="w-full h-full object-cover" />
        </div>
      </div>

      <DetailLayout
        aside={
          <FactsCard
            title="Activity details"
            facts={[
              { icon: FiFlag, label: "Type", value: activity.type && (activity.type === "indoor" ? "Indoor" : "Outdoor") },
              { icon: FiTag, label: "Activity Fee", value: price },
              { icon: FiClock, label: "Duration", value: activity.duration_minutes != null ? `${activity.duration_minutes} minutes` : null },
              { icon: FiUsers, label: "Capacity", value: activity.capacity != null ? `${activity.capacity} persons` : null },
              { icon: FiStar, label: "Best Time To Visit", value: activity.best_time_to_visit },
              { icon: FiSun, label: "What To Wear", value: activity.what_to_wear },
            ]}
          />
        }
      >
        {activity.description && (
          <DetailSection title="About this activity">
            <p className="text-gray-700 leading-relaxed whitespace-pre-line">{activity.description}</p>
          </DetailSection>
        )}

        {thingsToDo.length > 0 && (
          <DetailSection title="Things To Do">
            <ul className="space-y-3">
              {thingsToDo.map((item, i) => (
                <li key={i} className="flex gap-3 text-gray-700">
                  <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-secondary-500" aria-hidden="true" />
                  {item}
                </li>
              ))}
            </ul>
          </DetailSection>
        )}
      </DetailLayout>
    </div>
  );
}
