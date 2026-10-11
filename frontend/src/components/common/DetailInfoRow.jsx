export default function DetailInfoRow({ icon: Icon, label, children }) {
  if (children === null || children === undefined || children === '') return null

  return (
    <div className="flex items-start gap-3 py-2">
      <Icon size={17} className="text-primary-500 shrink-0 mt-0.5" />
      <p className="text-sm text-gray-700 leading-relaxed">
        <span className="font-semibold text-gray-900">{label}:</span> <span className="text-gray-600">{children}</span>
      </p>
    </div>
  )
}
