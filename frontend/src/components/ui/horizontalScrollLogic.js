/** Pure geometry for scoped, keyboard-accessible horizontal scrolling. */
export function scrollDistance(viewportWidth, cellWidth) {
  const viewport = Number.isFinite(viewportWidth) && viewportWidth > 0 ? viewportWidth : 300
  const cell = Number.isFinite(cellWidth) && cellWidth > 0 ? cellWidth : 0
  return Math.max(viewport * 0.78, cell * 2, 140)
}

export function nextHorizontalPosition(position, scrollWidth, viewportWidth, direction, cellWidth = 0) {
  if (![-1, 1].includes(direction)) return Math.max(0, position || 0)
  if (![position, scrollWidth, viewportWidth].every(Number.isFinite)) return 0
  const limit = Math.max(0, scrollWidth - viewportWidth)
  const distance = scrollDistance(viewportWidth, cellWidth)
  return Math.max(0, Math.min(limit, position + direction * distance))
}

export function horizontalScrollEdges(position, scrollWidth, viewportWidth) {
  const limit = Math.max(0, (Number(scrollWidth) || 0) - (Number(viewportWidth) || 0))
  return {
    canLeft: limit > 2 && position > 2,
    canRight: limit > 2 && position < limit - 2,
  }
}
