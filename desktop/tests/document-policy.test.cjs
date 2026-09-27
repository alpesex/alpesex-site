const test = require('node:test');
const assert = require('node:assert/strict');
const {MAX_DOCUMENT_BYTES, assertDocumentSize} = require('../document-policy.cjs');

test('la passerelle Windows accepte les documents jusqu’à 20 Mo inclus', () => {
  assert.equal(MAX_DOCUMENT_BYTES, 20 * 1024 * 1024);
  assert.doesNotThrow(() => assertDocumentSize(1024 * 1024));
  assert.doesNotThrow(() => assertDocumentSize(1024 * 1024 + 1));
  assert.doesNotThrow(() => assertDocumentSize(20 * 1024 * 1024));
});

test('la passerelle Windows refuse un document au-delà de 20 Mo', () => {
  assert.throws(
    () => assertDocumentSize(20 * 1024 * 1024 + 1),
    /limite de 20 Mo/
  );
});
