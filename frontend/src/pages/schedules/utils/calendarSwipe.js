// For the existing compact calendar: -1 = previous month, +1 = next month.
// A vertical page scroll or a tap must never change the month.
export function calendarSwipeDirection(startX, startY, endX, endY) {
  if (![startX, startY, endX, endY].every(Number.isFinite)) return 0
  const dx = endX - startX
  const dy = endY - startY
  if (Math.abs(dx) < 48 || Math.abs(dx) <= Math.abs(dy) * 1.35) return 0
  return dx < 0 ? 1 : -1
}
