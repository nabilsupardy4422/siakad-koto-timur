// Native top layer provides background inertness. Explicit wrap keeps keyboard focus in the active dialog.
export function nextDialogTabIndex(count, current, backwards) {
  if (!count) return -1;
  if (current < 0) return backwards ? count - 1 : 0;
  if (backwards && current === 0) return count - 1;
  if (!backwards && current === count - 1) return 0;
  return null;
}

export function containDialogTab(event) {
  const dialog = event.currentTarget;
  if (event.key !== 'Tab' || event.defaultPrevented || event.target.closest('dialog') !== dialog) return;
  const nodes = [...dialog.querySelectorAll('a[href],button,input,select,textarea,[tabindex],summary,[contenteditable="true"]')]
    .filter(node => node.closest('dialog') === dialog && node.tabIndex >= 0 && !node.matches(':disabled') && !node.closest('[inert],[hidden]') && node.getClientRects().length && getComputedStyle(node).visibility === 'visible');
  const index = nextDialogTabIndex(nodes.length, nodes.indexOf(document.activeElement), event.shiftKey);
  if (index === null) return;
  event.preventDefault();
  if (index >= 0) nodes[index].focus();
}
