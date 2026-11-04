import { useEffect, useState } from 'react'
import { FiPlus, FiEdit2, FiTrash2, FiAlertTriangle, FiGrid } from 'react-icons/fi'
import { getMyRooms, deleteRoom, toggleRoomOccupancy } from '../../../api/accommodationOwner'
import { getImageUrl } from '../../../utils/helpers'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import RoomFormModal from './RoomFormModal'
import ItemReviewStatus from '../../../components/business/ItemReviewStatus'
import { withProposedValues } from '../../../components/business/itemChanges'
import toast from 'react-hot-toast'

const MAX_ROOMS = 10

const CAPACITY_MESSAGE =
  'Maximum capacity reached: You have registered the limit of 10 rooms. Delete an existing room to add a new one.'

const REVIEW_FIELDS = [
  ['room_number', 'Room'],
  ['category', 'Category'],
  ['price', 'Price', (v) => '₱' + Number(v).toLocaleString()],
  ['description', 'Description'],
  ['room_features', 'Features'],
]

const STATUS_STYLES = {
  approved: 'bg-green-100 text-green-700',
  pending: 'bg-yellow-100 text-yellow-700',
  rejected: 'bg-red-100 text-red-700',
}

export default function RoomManagement() {
  const [rooms, setRooms] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingRoom, setEditingRoom] = useState(null)
  const [togglingId, setTogglingId] = useState(null)

  const load = () => getMyRooms().then(setRooms).finally(() => setLoading(false))

  useEffect(() => {
    load()
  }, [])

  const atCapacity = rooms.length >= MAX_ROOMS

  const openAdd = () => {
    if (atCapacity) return
    setEditingRoom(null)
    setShowForm(true)
  }

  const openEdit = (room) => {
    // Edit what the owner last proposed, so saving again keeps that proposal.
    setEditingRoom(withProposedValues(room))
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditingRoom(null)
  }

  const handleSaved = () => {
    closeForm()
    load()
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this room? This cannot be undone.')) return
    try {
      await deleteRoom(id)
      toast.success('Room deleted')
      load()
    } catch {
      toast.error('Failed to delete room')
    }
  }

  const handleToggleOccupancy = async (room) => {
    const next = room.occupancy_status === 'occupied' ? 'unoccupied' : 'occupied'
    setTogglingId(room.id)
    try {
      await toggleRoomOccupancy(room.id, next)
      setRooms((prev) => prev.map((r) => (r.id === room.id ? { ...r, occupancy_status: next } : r)))
    } catch {
      toast.error('Failed to update room status')
    } finally {
      setTogglingId(null)
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex items-end justify-between flex-wrap gap-4 mb-6">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Accommodation Owner Portal</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Room Management</h1>
          <p className="text-sm text-gray-500 mt-1">{rooms.length} / {MAX_ROOMS} rooms registered</p>
        </div>
        <button
          onClick={openAdd}
          disabled={atCapacity}
          className="btn-primary flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <FiPlus size={16} /> Add Room
        </button>
      </div>

      {atCapacity && (
        <div className="flex items-start gap-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg p-4 mb-6">
          <FiAlertTriangle className="shrink-0 mt-0.5" size={18} />
          <p className="text-sm font-medium">{CAPACITY_MESSAGE}</p>
        </div>
      )}

      {rooms.length === 0 ? (
        <div className="bg-white rounded-2xl p-12 text-center border border-gray-200/70 shadow-(--shadow-card)">
          <FiGrid className="mx-auto text-gray-300 mb-3" size={32} />
          <p className="text-gray-500">No rooms registered yet. Add your first room to get started.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
          {rooms.map((room) => (
            <div key={room.id} className="bg-white rounded-2xl border border-gray-200/70 overflow-hidden flex flex-col shadow-(--shadow-card)">
              <img src={getImageUrl(room.images?.[0])} alt={room.room_number} className="w-full h-36 object-cover bg-gray-100" />
              <div className="p-4 flex-1 flex flex-col">
                <div className="flex items-start justify-between gap-2 mb-1">
                  <h3 className="font-medium text-gray-900">{room.room_number}</h3>
                  <span className={`text-xs font-medium px-2 py-0.5 rounded-full shrink-0 capitalize ${STATUS_STYLES[room.status]}`}>
                    {room.status}
                  </span>
                </div>
                <div className="flex items-center gap-2 mb-1 text-sm text-gray-500">
                  {room.category && <span className="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs font-medium">{room.category}</span>}
                  {room.price && <span>₱{Number(room.price).toLocaleString()}</span>}
                </div>
                {room.description && <p className="text-sm text-gray-500 line-clamp-2 mb-3">{room.description}</p>}
                <ItemReviewStatus item={room} fields={REVIEW_FIELDS} noun="room" />

                <div className="mt-auto space-y-3">
                  <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-medium">
                    <button
                      type="button"
                      disabled={togglingId === room.id}
                      onClick={() => room.occupancy_status !== 'unoccupied' && handleToggleOccupancy(room)}
                      className={`flex-1 py-1.5 transition-colors ${
                        room.occupancy_status === 'unoccupied' ? 'bg-green-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'
                      }`}
                    >
                      Unoccupied
                    </button>
                    <button
                      type="button"
                      disabled={togglingId === room.id}
                      onClick={() => room.occupancy_status !== 'occupied' && handleToggleOccupancy(room)}
                      className={`flex-1 py-1.5 transition-colors ${
                        room.occupancy_status === 'occupied' ? 'bg-red-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'
                      }`}
                    >
                      Occupied
                    </button>
                  </div>

                  <div className="flex gap-4">
                    <button onClick={() => openEdit(room)} className="flex items-center gap-1.5 py-2 sm:py-0 text-primary-600 hover:text-primary-700 text-sm font-medium">
                      <FiEdit2 size={13} /> Edit
                    </button>
                    <button onClick={() => handleDelete(room.id)} className="flex items-center gap-1.5 py-2 sm:py-0 text-red-600 hover:text-red-700 text-sm font-medium">
                      <FiTrash2 size={13} /> Delete
                    </button>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {showForm && <RoomFormModal room={editingRoom} onClose={closeForm} onSaved={handleSaved} />}
    </div>
  )
}
