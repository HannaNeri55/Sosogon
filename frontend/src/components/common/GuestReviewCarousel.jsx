import { useRef, useState } from "react";
import { FiArrowLeft, FiArrowRight } from "react-icons/fi";
import { parseReview } from "../../utils/helpers";

export default function GuestReviewCarousel({ item, title = "Why Guests Love This Place" }) {
  const scrollRef = useRef(null);
  const [expandedReviews, setExpandedReviews] = useState({});

  const reviews = item?.ratings || [];
  const average = Number(item?.average_rating || 0);
  const total = item?.total_reviews ?? reviews.length;

  const scroll = (direction) => {
    const container = scrollRef.current;
    if (!container) return;

    const firstCard = container.querySelector("article");
    const cardWidth = firstCard?.getBoundingClientRect().width || 280;
    const gap = parseFloat(window.getComputedStyle(container).columnGap) || 16;

    container.scrollBy({
      left: direction * (cardWidth + gap),
      behavior: "smooth",
    });
  };

  const toggleExpanded = (id) => {
    setExpandedReviews((previous) => ({
      ...previous,
      [id]: !previous[id],
    }));
  };

  const formatDate = (date) => {
    if (!date) return "";
    const parsed = new Date(date);
    if (Number.isNaN(parsed.getTime())) return "";

    return parsed.toLocaleDateString("en-US", {
      month: "short",
      year: "numeric",
    });
  };

  return (
    <section className="py-4">
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h2 className="text-2xl font-heading font-bold text-gray-900">
          {title}
        </h2>

        {reviews.length > 0 && (
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex items-center gap-2 text-sm">
              <span className="font-semibold text-gray-800">
                {average.toFixed(1)}
              </span>
              <span className="text-amber-500 tracking-wide" aria-label={`${average.toFixed(1)} out of 5 stars`}>
                {"★".repeat(Math.round(Math.max(0, Math.min(5, average))))}
              </span>
              <span className="text-gray-500">
                ({total} {total === 1 ? "review" : "reviews"})
              </span>
            </div>

            {reviews.length > 1 && (
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => scroll(-1)}
                  aria-label="Previous reviews"
                  className="h-10 w-10 flex items-center justify-center rounded-full border border-gray-300 bg-white hover:bg-gray-100"
                >
                  <FiArrowLeft />
                </button>
                <button
                  type="button"
                  onClick={() => scroll(1)}
                  aria-label="Next reviews"
                  className="h-10 w-10 flex items-center justify-center rounded-full border border-gray-300 bg-white hover:bg-gray-100"
                >
                  <FiArrowRight />
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      {reviews.length > 0 ? (
        <div
          ref={scrollRef}
          className="flex gap-4 overflow-x-auto scroll-smooth snap-x snap-mandatory pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
          {reviews.map((review) => {
            const { comment } = parseReview(review);
            const reply = review.reply?.body || "";
            const isExpanded = Boolean(expandedReviews[review.id]);
            const isLong = comment.length > 145 || reply.length > 90;
            const dateLabel = formatDate(review.created_at);

            return (
              <article
                key={review.id}
                className="flex-none w-[280px] lg:w-[calc((100%-48px)/4)] min-h-[265px] snap-start rounded-xl border border-gray-200 bg-white p-5"
              >
                <div className="flex items-center gap-2 mb-5">
                  <span
                    className="text-amber-500 text-base tracking-tight"
                    aria-label={`${review.score} out of 5 stars`}
                  >
                    {"★".repeat(Math.max(0, Math.min(5, Number(review.score) || 0)))}
                  </span>
                </div>

                <div className="mb-4 min-h-[48px]">
                  <p className="text-sm font-semibold text-gray-900">
                    {review.user?.name || "Guest"}
                  </p>
                  {dateLabel && (
                    <p className="text-xs text-gray-500 mt-1">{dateLabel}</p>
                  )}
                </div>

                <p
                  className={`text-gray-700 text-base leading-relaxed ${
                    !isExpanded && isLong ? "line-clamp-5" : ""
                  }`}
                >
                  {comment || "No written review provided."}
                </p>

                {reply && (
                  <div className="mt-4 border-l-2 border-primary-500 pl-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-primary-700">
                      Owner response
                    </p>
                    <p
                      className={`mt-1 text-sm text-gray-600 leading-relaxed ${
                        !isExpanded && isLong ? "line-clamp-3" : ""
                      }`}
                    >
                      {reply}
                    </p>
                  </div>
                )}

                {isLong && (
                  <button
                    type="button"
                    onClick={() => toggleExpanded(review.id)}
                    className="mt-2 text-sm font-semibold text-gray-900 underline underline-offset-2"
                  >
                    {isExpanded ? "Read less" : "Read more"}
                  </button>
                )}
              </article>
            );
          })}
        </div>
      ) : (
        <p className="text-gray-500">No guest reviews available yet.</p>
      )}
    </section>
  );
}
