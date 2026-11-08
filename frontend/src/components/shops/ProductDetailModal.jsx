// frontend/src/components/shops/ProductDetailModal.jsx
import Modal from '../common/Modal'
import { formatProductPrice, getImageUrl } from '../../utils/helpers'

/** One catalog product in full: its photo, type, price when given, and complete description. */
export default function ProductDetailModal({ product, shopName, image, onClose }) {
  return (
    <Modal title={product.name} subtitle={shopName ? `Available at ${shopName}` : undefined} size="lg" onClose={onClose}>
      <img
        src={getImageUrl(image)}
        alt={product.name}
        className="w-full aspect-4/3 max-h-96 rounded-xl object-cover bg-gray-100"
      />
      <div className="mt-4 flex flex-wrap items-center gap-3">
        {product.product_type && (
          <span className="inline-block text-xs font-medium text-secondary-700 bg-secondary-50 px-2.5 py-1 rounded-full">
            {product.product_type}
          </span>
        )}
        {product.price != null && (
          <span className="text-base font-semibold text-primary-700">{formatProductPrice(product.price)}</span>
        )}
      </div>
      {product.description && (
        <p className="mt-4 text-gray-700 leading-relaxed whitespace-pre-line">{product.description}</p>
      )}
    </Modal>
  )
}
