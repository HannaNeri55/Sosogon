// frontend/src/pages/admin/ManageUsers.jsx
import { useEffect, useMemo, useState } from 'react'
import { FiSearch, FiUserCheck, FiUserX, FiTrash2 } from 'react-icons/fi'
import { getAdminUsers, updateAdminUser, deleteAdminUser } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import toast from 'react-hot-toast'

const ROLE_STYLES = {
  admin: 'bg-primary-50 text-primary-700',
  business_owner: 'bg-secondary-50 text-secondary-700',
  tourist: 'bg-gray-100 text-gray-600',
}

export default function ManageUsers() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  const loadData = async () => {
    setLoading(true)
    try {
      const data = await getAdminUsers({ per_page: 100 })
      setItems(data.data || [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData()
  }, [])

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((u) => u.name?.toLowerCase().includes(q) || u.email?.toLowerCase().includes(q))
  }, [items, search])

  const toggleStatus = async (user) => {
    const newStatus = user.status === 'active' ? 'suspended' : 'active'
    try {
      await updateAdminUser(user.id, { status: newStatus })
      toast.success('User status updated')
      loadData()
    } catch {
      toast.error('Failed to update user')
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('Delete this user?')) return
    try {
      await deleteAdminUser(id)
      toast.success('User deleted')
      loadData()
    } catch (e) {
      toast.error(e.response?.data?.message || 'Failed to delete user')
    }
  }

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-4xl font-heading font-bold text-gray-900">Manage Users</h1>
      </div>

      <div className="relative mb-5 max-w-sm">
        <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
        <input
          className="input-field pl-9"
          placeholder="Search by name or email..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">User</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Role</th>
                <th className="text-left px-6 py-3 text-sm font-medium text-gray-700">Status</th>
                <th className="text-right px-6 py-3 text-sm font-medium text-gray-700">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((user) => (
                <tr key={user.id} className="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                  <td className="px-6 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-9 h-9 rounded-full bg-primary-50 text-primary-600 flex items-center justify-center font-heading font-semibold text-sm shrink-0">
                        {user.name.charAt(0).toUpperCase()}
                      </div>
                      <div>
                        <p className="text-sm font-medium text-gray-900">{user.name}</p>
                        <p className="text-xs text-gray-400">{user.email}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${ROLE_STYLES[user.role?.name] || 'bg-gray-100 text-gray-600'}`}>
                      {user.role?.name?.replace('_', ' ')}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${user.status === 'active' ? 'bg-green-100 text-green-700' : user.status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600'}`}>
                      {user.status}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    {user.role?.name !== 'admin' && (
                      <div className="flex gap-3 justify-end">
                        <button
                          onClick={() => toggleStatus(user)}
                          className={`p-2 rounded-lg transition-colors ${user.status === 'active' ? 'text-gray-400 hover:text-yellow-600 hover:bg-yellow-50' : 'text-gray-400 hover:text-green-600 hover:bg-green-50'}`}
                          title={user.status === 'active' ? 'Suspend' : 'Activate'}
                        >
                          {user.status === 'active' ? <FiUserX size={15} /> : <FiUserCheck size={15} />}
                        </button>
                        <button onClick={() => handleDelete(user.id)} className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                          <FiTrash2 size={15} />
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}