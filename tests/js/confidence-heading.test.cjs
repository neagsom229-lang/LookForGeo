const test = require('node:test');
const assert = require('node:assert/strict');
const {
  CONFIRMED_HEADING_MIN,
  CONFIRMED_LABEL,
  POSSIBLE_LABEL,
  evidenceHeading
} = require('../../public/js/confidence-heading.js');

test('confirmed heading requires the defined threshold', () => {
  assert.equal(CONFIRMED_HEADING_MIN, 65);
  assert.equal(evidenceHeading(65, true, false), CONFIRMED_LABEL);
  assert.equal(evidenceHeading(95, true, false), CONFIRMED_LABEL);
});

test('below the threshold is never called confirmed', () => {
  assert.equal(evidenceHeading(64, true, false), POSSIBLE_LABEL);
  assert.equal(evidenceHeading(49, true, true), POSSIBLE_LABEL);
  assert.equal(evidenceHeading(20, true, true), POSSIBLE_LABEL);
});

test('missing coordinates can never be confirmed', () => {
  assert.equal(evidenceHeading(95, false, false), POSSIBLE_LABEL);
  assert.equal(evidenceHeading(95, undefined, false), POSSIBLE_LABEL);
});

test('low_confidence flag wins over a high numeric score', () => {
  assert.equal(evidenceHeading(95, true, true), POSSIBLE_LABEL);
});

test('non-numeric confidence is not confirmed', () => {
  assert.equal(evidenceHeading(null, true, false), POSSIBLE_LABEL);
  assert.equal(evidenceHeading('high', true, false), POSSIBLE_LABEL);
});
