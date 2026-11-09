import Modal from './Modal'

/** The dialog a sign-in page shows when the sign-in is refused (see utils/loginErrors.js). */
export default function LoginAlert({ alert, onClose }) {
  if (!alert) return null

  return (
    <Modal
      variant="alert"
      icon={alert.icon}
      title={alert.title}
      onClose={onClose}
      footer={<button type="button" className="btn-primary" onClick={onClose} autoFocus>{alert.button}</button>}
    >
      {alert.message}
    </Modal>
  )
}
