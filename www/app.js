const DB_NAME = 'koopal_offline_reader';
const DB_VERSION = 1;
const STORE_NAME = 'books';

const state = {
  books: [],
  currentBookId: null,
  toastTimer: null,
};

const refs = {
  libraryView: document.getElementById('library-view'),
  readerView: document.getElementById('reader-view'),
  importTrigger: document.getElementById('import-trigger'),
  importForm: document.getElementById('import-form'),
  titleInput: document.getElementById('book-title'),
  fileInput: document.getElementById('page-files'),
  libraryGrid: document.getElementById('library-grid'),
  emptyState: document.getElementById('empty-state'),
  bookCount: document.getElementById('book-count'),
  librarySubcopy: document.getElementById('library-subcopy'),
  backToLibrary: document.getElementById('back-to-library'),
  deleteBook: document.getElementById('delete-book'),
  readerTitle: document.getElementById('reader-title'),
  readerCount: document.getElementById('reader-count'),
  readerPages: document.getElementById('reader-pages'),
  toast: document.getElementById('toast'),
  cardTemplate: document.getElementById('book-card-template'),
};

document.addEventListener('DOMContentLoaded', () => {
  refs.importTrigger.addEventListener('click', () => refs.fileInput.click());
  refs.importForm.addEventListener('submit', handleImport);
  refs.backToLibrary.addEventListener('click', closeReader);
  refs.deleteBook.addEventListener('click', handleDeleteCurrentBook);
  init().catch((error) => {
    console.error(error);
    showToast('Unable to load offline library.');
  });
});

async function init() {
  await refreshBooks();
}

function openDatabase() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'id' });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function readAllBooks() {
  const db = await openDatabase();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readonly');
    const store = transaction.objectStore(STORE_NAME);
    const request = store.getAll();

    request.onsuccess = () => {
      resolve((request.result || []).sort((a, b) => b.updatedAt - a.updatedAt));
    };
    request.onerror = () => reject(request.error);
    transaction.oncomplete = () => db.close();
  });
}

async function writeBook(book) {
  const db = await openDatabase();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readwrite');
    transaction.objectStore(STORE_NAME).put(book);
    transaction.oncomplete = () => {
      db.close();
      resolve();
    };
    transaction.onerror = () => reject(transaction.error);
  });
}

async function deleteBook(bookId) {
  const db = await openDatabase();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readwrite');
    transaction.objectStore(STORE_NAME).delete(bookId);
    transaction.oncomplete = () => {
      db.close();
      resolve();
    };
    transaction.onerror = () => reject(transaction.error);
  });
}

async function refreshBooks() {
  state.books = await readAllBooks();
  renderLibrary();
}

async function handleImport(event) {
  event.preventDefault();

  const files = Array.from(refs.fileInput.files || []).filter((file) => file.type.startsWith('image/'));
  if (!files.length) {
    showToast('Select one or more image files first.');
    return;
  }

  const sortedFiles = files.sort((a, b) =>
    a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' })
  );

  const title = refs.titleInput.value.trim() || deriveTitle(sortedFiles);
  const pages = sortedFiles.map((file, index) => ({
    id: `${Date.now()}-${index}-${file.name}`,
    name: file.name,
    type: file.type,
    blob: file,
  }));

  const now = Date.now();
  const book = {
    id: crypto.randomUUID ? crypto.randomUUID() : String(now),
    title,
    createdAt: now,
    updatedAt: now,
    pageCount: pages.length,
    pages,
  };

  await writeBook(book);
  refs.importForm.reset();
  refs.titleInput.value = '';
  await refreshBooks();
  showToast(`Saved "${title}" for offline reading.`);
}

function deriveTitle(files) {
  const first = files[0]?.name || 'Untitled Book';
  return first.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim() || 'Untitled Book';
}

function renderLibrary() {
  refs.libraryGrid.innerHTML = '';
  refs.bookCount.textContent = String(state.books.length);
  refs.emptyState.hidden = state.books.length > 0;
  refs.librarySubcopy.textContent = state.books.length
    ? `${state.books.length} saved ${state.books.length === 1 ? 'book' : 'books'} ready for offline reading.`
    : 'No saved books yet.';

  state.books.forEach((book) => {
    const fragment = refs.cardTemplate.content.cloneNode(true);
    const card = fragment.querySelector('.book-card');
    const openBtn = fragment.querySelector('.book-open');
    const deleteBtn = fragment.querySelector('.card-delete');
    const cover = fragment.querySelector('.cover-image');
    const title = fragment.querySelector('.book-title');
    const meta = fragment.querySelector('.book-meta');

    const firstPage = book.pages?.[0];
    if (firstPage?.blob) {
      cover.src = URL.createObjectURL(firstPage.blob);
      cover.onload = () => URL.revokeObjectURL(cover.src);
    }
    cover.alt = `${book.title} cover`;
    title.textContent = book.title;
    meta.textContent = `${book.pageCount} page${book.pageCount === 1 ? '' : 's'}`;

    openBtn.addEventListener('click', () => openReader(book.id));
    deleteBtn.addEventListener('click', async (event) => {
      event.stopPropagation();
      await handleDeleteBook(book.id, book.title);
    });

    refs.libraryGrid.appendChild(card);
  });
}

function openReader(bookId) {
  const book = state.books.find((entry) => entry.id === bookId);
  if (!book) {
    showToast('Book not found.');
    return;
  }

  state.currentBookId = book.id;
  refs.readerTitle.textContent = book.title;
  refs.readerCount.textContent = `${book.pageCount} page${book.pageCount === 1 ? '' : 's'}`;
  refs.readerPages.innerHTML = '';

  book.pages.forEach((page, index) => {
    const img = document.createElement('img');
    const url = URL.createObjectURL(page.blob);
    img.src = url;
    img.alt = `${book.title} page ${index + 1}`;
    img.loading = index < 2 ? 'eager' : 'lazy';
    img.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
    refs.readerPages.appendChild(img);
  });

  refs.libraryView.classList.remove('active');
  refs.readerView.classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function closeReader() {
  state.currentBookId = null;
  refs.readerPages.innerHTML = '';
  refs.readerView.classList.remove('active');
  refs.libraryView.classList.add('active');
}

async function handleDeleteCurrentBook() {
  if (!state.currentBookId) {
    return;
  }

  const current = state.books.find((book) => book.id === state.currentBookId);
  if (!current) {
    return;
  }

  await handleDeleteBook(current.id, current.title);
  closeReader();
}

async function handleDeleteBook(bookId, title) {
  const confirmed = window.confirm(`Delete "${title}" from this device?`);
  if (!confirmed) {
    return;
  }

  await deleteBook(bookId);
  await refreshBooks();
  showToast(`Deleted "${title}".`);
}

function showToast(message) {
  refs.toast.textContent = message;
  refs.toast.classList.add('visible');
  window.clearTimeout(state.toastTimer);
  state.toastTimer = window.setTimeout(() => {
    refs.toast.classList.remove('visible');
  }, 2400);
}
