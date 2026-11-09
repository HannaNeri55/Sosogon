import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiPlus, FiEdit2, FiTrash2, FiAlertTriangle, FiGrid, FiHome } from 'react-icons/fi'
import { getMyAccommodation, getMyRooms, deleteRoom } from '../../../api/accommodationOwner'
import { isEntireProperty, optionFacts, optionImage, optionName, optionPrice, unitTypeLabel } from '../../../utils/accommodationOptions'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import RoomFormModal from './RoomFormModal'
import ItemReviewStatus from '../../../components/business/ItemReviewStatus'
import { withProposedValues } from '../../../components/business/itemChanges'
import toast from 'react-hot-toast'
import { showApiError } from '../../../utils/apiErrors'
import { confirmDialog } from '../../../utils/dialogs'

const MAX_OPTIONS = 30

const CAPACITY_MESSAGE =
  'Maximum reached: a property can list up to 30 accommodation options. Group similar rooms into one option, or delete one to add another.'

const REVIEW_FIELDS = [
  ['name', 'Name'],
  ['unit_type', 'Type', unitTypeLabel],
  ['category', 'Category'],
  ['unit_count', 'How many'],
  ['max_guests', 'Maximum guests'],
  ['bedrooms', 'Bedrooms'],
  ['bed_configuration', 'Beds'],
  ['size_sqm', 'Size (sqm)'],
  ['pricing_mode', 'Rates'],
  ['price', 'Price', (v) => (Number(v) > 0 ? '₱' + Number(v).toLocaleString() : 'Not provided')],
  ['price_max', 'Up to', (v) => (Number(v) > 0 ? '₱' + Number(v).toLocaleString() : 'Not provided')],
  ['price_note', 'What it covers'],
  ['description', 'Description'],
  ['notes', 'Notes'],
  ['room_features', 'Amenities'],
]

const STATUS_STYLES = {
  approved: 'bg-green-100 text-green-700',
  pending: 'bg-yellow-100 text-yellow-700',
  rejected: 'bg-red-100 text-red-700',
}

function OptionCard({ option, onEdit, onDelete }) {
  const price = optionPrice(option)
  const facts = optionFacts(option)
  return (
    <div className="bg-white rounded-2xl border border-gray-200/70 overflow-hidden flex flex-col shadow-(--shadow-card)">
      <img src={optionImage(option.images?.[0])} alt={optionName(option)} className="w-full h-36 object-cover bg-gray-100" />
      <div className="p-4 flex-1 flex flex-col">
        <div className="flex items-start justify-between gap-2 mb-1">
          <h3 className="font-medium text-gray-900">{optionName(option)}</h3>
          <span className={`text-xs font-medium px-2 py-0.5 rounded-full shrink-0 capitalize ${STATUS_STYLES[option.status]}`}>
            {option.status}
          </span>
        </div>
        <div className="flex flex-wrap items-center gap-2 mb-1 text-sm text-gray-500">
          <span className="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs font-medium">{option.category || unitTypeLabel(option.unit_type)}</span>
          <span>{price.amount || 'No price listed'}</span>
        </div>
        {facts.length > 0 && <p className="text-xs text-gray-500 mb-1">{facts.join(' · ')}</p>}
        {option.description && <p className="text-sm text-gray-500 line-clamp-2 mb-3">{option.description}</p>}
        <ItemReviewStatus item={option} fields={REVIEW_FIELDS} noun="option" />

        <div className="mt-auto flex gap-4 pt-2">
          <button onClick={() => onEdit(option)} className="flex items-center gap-1.5 py-2 sm:py-0 text-primary-600 hover:text-primary-700 text-sm font-medium">
            <FiEdit2 size={13} /> Edit
          </button>
          <button onClick={() => onDelete(option.id)} className="flex items-center gap-1.5 py-2 sm:py-0 text-red-600 hover:text-red-700 text-sm font-medium">
            <FiTrash2 size={13} /> Delete
          </button>
        </div>
      </div>
    </div>
  )
}

/** The property's accommodation options, shown according to what the owner says they offer. */
export default function RoomManagement() {
  const [rooms, setRooms] = useState([])
  const [arrangement, setArrangement] = useState('units')
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState(null) // { room, entireProperty }

  const load = () => getMyRooms().then(setRooms).finally(() => setLoading(false))

  useEffect(() => {
    load()
    // What the owner last chose, even while that choice awaits review.
    getMyAccommodation()
      .then((property) => setArrangement(property.owner_revision?.proposed_data?.offering_arrangement || property.offering_arrangement || 'units'))
      .catch(() => {})
  }, [])

  const units = rooms.filter((r) => !isEntireProperty(r))
  const whole = rooms.find(isEntireProperty)
  const atCapacity = rooms.length >= MAX_OPTIONS
  const offersUnits = arrangement !== 'entire_property' || units.length > 0
  const offersWhole = arrangement !== 'units' || Boolean(whole)

  // Edit what the owner last proposed, so saving again keeps that proposal.
  const openEdit = (room) => setForm({ room: withProposedValues(room) })
  const closeForm = () => setForm(null)
  const handleSaved = () => {
    closeForm()
    load()
  }

  const handleDelete = async (id) => {
    if (!(await confirmDialog({ title: 'Delete this option?', message: 'This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return
    try {
      await deleteRoom(id)
      toast.success('Option deleted')
      load()
    } catch (error) {
      showApiError(error, 'Failed to delete option')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex items-end justify-between flex-wrap gap-4 mb-6">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Accommodation Owner Portal</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Accommodation Options</h1>
          <p className="text-sm text-gray-500 mt-1">
            What tourists can stay in. You choose what your property offers in{' '}
            <Link to="/business/accommodation/property" className="text-primary-600 hover:underline">My Property</Link>.
          </p>
        </div>
        {offersUnits && (
          <button onClick={() => !atCapacity && setForm({ room: null })} disabled={atCapacity} className="btn-primary flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
            <FiPlus size={16} /> Add Option
          </button>
        )}
      </div>

      {atCapacity && (
        <div className="flex items-start gap-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg p-4 mb-6">
          <FiAlertTriangle className="shrink-0 mt-0.5" size={18} />
          <p className="text-sm font-medium">{CAPACITY_MESSAGE}</p>
        </div>
      )}

      {offersWhole && (
        <section className="mb-8">
          <h2 className="text-lg font-heading font-semibold text-gray-900 mb-3">Entire property</h2>
          {whole ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
              <OptionCard option={whole} onEdit={openEdit} onDelete={handleDelete} />
            </div>
          ) : (
            <div className="bg-white rounded-2xl p-6 border border-gray-200/70 shadow-(--shadow-card) flex flex-wrap items-center justify-between gap-4">
              <p className="text-sm text-gray-600 max-w-xl">
                Your property page already shows your details and facilities. You can add capacity, rates and photos for renting the whole place, but it's optional.
              </p>
              <button onClick={() => setForm({ room: null, entireProperty: true })} className="inline-flex items-center gap-2 rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50">
                <FiHome size={15} aria-hidden="true" /> Add details
              </button>
            </div>
          )}
        </section>
      )}

      {offersUnits && (
        <section>
          {offersWhole && <h2 className="text-lg font-heading font-semibold text-gray-900 mb-3">Individual units</h2>}
          {units.length === 0 ? (
            <div className="bg-white rounded-2xl p-12 text-center border border-gray-200/70 shadow-(--shadow-card)">
              <FiGrid className="mx-auto text-gray-300 mb-3" size={32} />
              <p className="text-gray-500">No options yet. Add a room type, villa or cottage to get started. One is enough.</p>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
              {units.map((room) => <OptionCard key={room.id} option={room} onEdit={openEdit} onDelete={handleDelete} />)}
            </div>
          )}
        </section>
      )}

      {form && <RoomFormModal room={form.room} entireProperty={form.entireProperty} onClose={closeForm} onSaved={handleSaved} />}
    </div>
  )
}
