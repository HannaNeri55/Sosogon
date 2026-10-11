export default function StatCard({ icon: Icon, label, value, accent = 'primary' }) {
  const accentClasses = {
    primary: 'bg-primary-50 text-primary-600',
    secondary: 'bg-secondary-50 text-secondary-600',
    yellow: 'bg-yellow-50 text-yellow-600',
    green: 'bg-green-50 text-green-600',
    red: 'bg-red-50 text-red-600',
  }

  return (
    // Phones (two tiles per row): icon above the number and the label may wrap, so it isn't cut off.
    <div className="bg-white rounded-xl border border-gray-100 p-4 sm:p-5 flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4">
      <div className={`w-10 h-10 sm:w-11 sm:h-11 rounded-lg flex items-center justify-center shrink-0 ${accentClasses[accent]}`}>
        <Icon size={20} />
      </div>
      <div className="min-w-0">
        <p className="text-2xl font-heading font-bold text-gray-900 leading-tight">{value}</p>
        <p className="text-sm text-gray-500 leading-snug sm:truncate">{label}</p>
      </div>
    </div>
  )
}