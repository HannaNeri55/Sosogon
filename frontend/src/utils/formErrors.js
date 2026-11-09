/**
 * After a form shows its errors, moves the cursor to the first field marked aria-invalid and
 * scrolls it into view, so an error at the top of a long form isn't missed. Fields that can't
 * take focus (e.g. a hidden file input) scroll their message into view instead.
 */
export function focusFirstInvalid() {
  // Wait for React to render the new error state.
  requestAnimationFrame(() => {
    const field = [...document.querySelectorAll('[aria-invalid="true"]')].find((el) => el.offsetParent !== null)
    if (field) {
      field.focus({ preventScroll: true })
      field.scrollIntoView({ block: 'center', behavior: 'smooth' })
      return
    }
    document.querySelector('.field-error')?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  })
}
