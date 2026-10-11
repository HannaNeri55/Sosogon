export default function LoadingSpinner({ size = 'md' }) {
  const sizeClass = size === 'sm' ? 'h-5 w-5' : size === 'lg' ? 'h-12 w-12' : 'h-8 w-8'
  return (
    <div className="flex items-center justify-center py-12">
      <div className={`animate-spin rounded-full border-b-2 border-primary-500 ${sizeClass}`}></div>
    </div>
  )
}