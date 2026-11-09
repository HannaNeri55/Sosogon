import { useEffect, useState } from 'react'
import { FiPlus, FiEdit2, FiTrash2, FiAlertTriangle, FiPackage } from 'react-icons/fi'
import { getMyRetailProducts, deleteRetailShopProduct } from '../../../api/retailShopOwner'
import { formatProductPrice, getImageUrl } from '../../../utils/helpers'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import ProductFormModal from './ProductFormModal'
import ItemReviewStatus from '../../../components/business/ItemReviewStatus'
import { withProposedValues } from '../../../components/business/itemChanges'
import toast from 'react-hot-toast'

const MAX_PRODUCTS = 20

const CAPACITY_MESSAGE =
  'Maximum capacity reached: You have registered the limit of 20 products. Delete an existing product to feature a new one.'

const REVIEW_FIELDS = [
  ['name', 'Name'],
  ['product_type', 'Type'],
  ['description', 'Description'],
  ['price', 'Price', (v) => formatProductPrice(v) ?? '—'],
  ['website', 'Website'],
]

const STATUS_STYLES = {
  approved: 'bg-green-100 text-green-700',
  pending: 'bg-yellow-100 text-yellow-700',
  rejected: 'bg-red-100 text-red-700',
}

export default function ProductManagement() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [editingItem, setEditingItem] = useState(null)

  const load = () => getMyRetailProducts().then(setItems).finally(() => setLoading(false))

  useEffect(() => {
    load()
  }, [])

  const atCapacity = items.length >= MAX_PRODUCTS

  const openAdd = () => {
    if (atCapacity) return
    setEditingItem(null)
    setShowForm(true)
  }

  const openEdit = (item) => {
    // Edit what the owner last proposed, so saving again keeps that proposal.
    setEditingItem(withProposedValues(item))
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
    if (!confirm('Delete this product? This cannot be undone.')) return
    try {
      await deleteRetailShopProduct(id)
      toast.success('Product deleted')
      load()
    } catch {
      toast.error('Failed to delete product')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="flex items-end justify-between flex-wrap gap-4 mb-6">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Local Products Owner Portal</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Product Catalog</h1>
          <p className="text-sm text-gray-500 mt-1">{items.length} / {MAX_PRODUCTS} products registered</p>
        </div>
        <button
          onClick={openAdd}
          disabled={atCapacity}
          className="btn-primary flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <FiPlus size={16} /> Add Product
        </button>
      </div>

      {atCapacity && (
        <div className="flex items-start gap-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg p-4 mb-6">
          <FiAlertTriangle className="shrink-0 mt-0.5" size={18} />
          <p className="text-sm font-medium">{CAPACITY_MESSAGE}</p>
        </div>
      )}

      <p className="text-xs text-gray-400 mb-6">
        This catalog is a digital, view-only display for tourists to browse before visiting your store in person — it does not support online ordering, payment, or live stock tracking.
      </p>

      {items.length === 0 ? (
        <div className="bg-white rounded-2xl p-12 text-center border border-gray-200/70 shadow-(--shadow-card)">
          <FiPackage className="mx-auto text-gray-300 mb-3" size={32} />
          <p className="text-gray-500">No products registered yet. Add your first product to get started.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
          {items.map((item) => (
            <div key={item.id} className="bg-white rounded-2xl border border-gray-200/70 overflow-hidden flex flex-col shadow-(--shadow-card)">
              <img src={getImageUrl(item.images?.[0])} alt={item.name} className="w-full h-36 object-cover bg-gray-100" />
              <div className="p-4 flex-1 flex flex-col">
                <div className="flex items-start justify-between gap-2 mb-1">
                  <h3 className="font-medium text-gray-900">{item.name}</h3>
                  <span className={`text-xs font-medium px-2 py-0.5 rounded-full shrink-0 capitalize ${STATUS_STYLES[item.status]}`}>
                    {item.status}
                  </span>
                </div>
                <span className="text-xs font-medium text-primary-600 bg-primary-50 px-2 py-0.5 rounded self-start mb-2">
                  {item.product_type}
                </span>
                {item.description && <p className="text-sm text-gray-500 line-clamp-2 mb-2">{item.description}</p>}
                {item.price != null && <p className="text-sm font-semibold text-primary-700 mb-1">{formatProductPrice(item.price)}</p>}
                <ItemReviewStatus item={item} fields={REVIEW_FIELDS} noun="product" />

                <div className="mt-auto flex gap-4 pt-2">
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

      {showForm && <ProductFormModal item={editingItem} onClose={closeForm} onSaved={handleSaved} />}
    </div>
  )
}
