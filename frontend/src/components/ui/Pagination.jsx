export default function Pagination({
  currentPage = 1,
  totalPages = 1,
  onPageChange,
}) {
  if (totalPages <= 1) {
    return null;
  }

  function goToPage(page) {
    if (page < 1 || page > totalPages || page === currentPage) {
      return;
    }

    onPageChange(page);
  }

  return (
    <nav className="ui-pagination" aria-label="Navigasi halaman">
      <button
        type="button"
        className="ui-pagination__button"
        disabled={currentPage === 1}
        onClick={() => goToPage(currentPage - 1)}
      >
        Sebelumnya
      </button>

      <span className="ui-pagination__status">
        Halaman {currentPage} dari {totalPages}
      </span>

      <button
        type="button"
        className="ui-pagination__button"
        disabled={currentPage === totalPages}
        onClick={() => goToPage(currentPage + 1)}
      >
        Berikutnya
      </button>
    </nav>
  );
}
