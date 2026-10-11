import { useEffect, useState } from 'react'
import { FiPlus, FiEdit2, FiTrash2, FiAlertTriangle, FiBook } from 'react-icons/fi'
import { getMyMenuItems, deleteMenuItem } from '../../../api/foodBeverageOwner'
import { getImageUrl } from '../../../utils/helpers'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import MenuItemFormModal from './MenuItemFormModal'
import toast from 'react-hot-toast'

const MAX_MENU_ITEMS = 20

const CAPACITY_MESSAGE =
  'Maximum capacity reached: You have registered the limit of 20 menu items. Delete an existing dish to add a new one.'

const STATUS_STYLES = {
  approved: 'bg-green-100 text-green-700',
  pending: 'bg-yellow-100 text-yellow-700',
  rejected: 'bg-red-100 text-red-700',
}

export default function MenuManagement() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingItem, setEditingItem] = useState(null)

  const load = () => getMyMenuItems().then(setItems).finally(() => setLoading(false))

  useEffect(() => {
    load()
  }, [])

  const atCapacity = items.length >= MAX_MENU_ITEMS

  const openAdd = () => {
    if (atCapacity) return
    setEditingItem(null)
    setShowForm(true)
  }

  const openEdit = (item) => {
    setEditingItem(item)
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    setEditingItem(null)
  }

  const handleSaved = () => {
    closeForm()
    load()
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this dish? This cannot be undone.')) return
    try {
      await deleteMenuItem(id)
      toast.success('Dish deleted')
      load()
    } catch {
      toast.error('Failed to delete dish')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex items-end justify-between flex-wrap gap-4 mb-6">
        <div>
          <p className="text-primary-600 font-medium mb-2">Food &amp; Beverage Owner Portal</p>
          <h1 className="text-3xl font-heading font-bold text-gray-900">Menu Management</h1>
          <p className="text-sm text-gray-500 mt-1">{items.length} / {MAX_MENU_ITEMS} menu items registered</p>
        </div>
        <button
          onClick={openAdd}
          disabled={atCapacity}
          className="btn-primary flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <FiPlus size={16} /> Add Dish
        </button>
      </div>

      {atCapacity && (
        <div className="flex items-start gap-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg p-4 mb-6">
          <FiAlertTriangle className="shrink-0 mt-0.5" size={18} />
          <p className="text-sm font-medium">{CAPACITY_MESSAGE}</p>
        </div>
      )}

      {items.length === 0 ? (
        <div className="bg-white rounded-xl p-12 text-center border border-gray-100">
          <FiBook className="mx-auto text-gray-300 mb-3" size={32} />
          <p className="text-gray-500">No dishes registered yet. Add your first dish to get started.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
          {items.map((item) => (
            <div key={item.id} className="bg-white rounded-xl border border-gray-100 overflow-hidden flex flex-col">
              <img src={getImageUrl(item)} alt={item.name} className="w-full h-36 object-cover bg-gray-100" />
              <div className="p-4 flex-1 flex flex-col">
                <div className="flex items-start justify-between gap-2 mb-1">
                  <h3 className="font-medium text-gray-900">{item.name}</h3>
                  <span className={`text-xs font-medium px-2 py-0.5 rounded-full shrink-0 capitalize ${STATUS_STYLES[item.status]}`}>
                    {item.status}
                  </span>
                </div>
                {item.description && <p className="text-sm text-gray-500 line-clamp-2 mb-2">{item.description}</p>}
                <p className="text-sm font-semibold text-primary-700 mb-3">₱{Number(item.price).toFixed(2)}</p>

                <div className="mt-auto flex gap-4">
                  <button onClick={() => openEdit(item)} className="flex items-center gap-1.5 py-2 sm:py-0 text-primary-600 hover:text-primary-700 text-sm font-medium">
                    <FiEdit2 size={13} /> Edit
                  </button>
                  <button onClick={() => handleDelete(item.id)} className="flex items-center gap-1.5 py-2 sm:py-0 text-red-600 hover:text-red-700 text-sm font-medium">
                    <FiTrash2 size={13} /> Delete
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {showForm && <MenuItemFormModal item={editingItem} onClose={closeForm} onSaved={handleSaved} />}
    </div>
  )
}
