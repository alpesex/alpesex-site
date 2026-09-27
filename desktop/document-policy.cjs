const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;

function assertDocumentSize(byteLength) {
  if (!Number.isSafeInteger(byteLength) || byteLength < 0) {
    throw new Error('Taille de document invalide');
  }
  if (byteLength > MAX_DOCUMENT_BYTES) {
    throw new Error('Le document dépasse la limite de 20 Mo.');
  }
}

module.exports = {MAX_DOCUMENT_BYTES, assertDocumentSize};
