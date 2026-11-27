const test = require('node:test');
const assert = require('node:assert/strict');
const {
  isApproximateArea,
  shouldDrawUncertaintyCircle,
  approximateAreaLabel
} = require('../../public/js/location-precision.js');

test('approximate answers are labelled as Approximate area, not a pin', () => {
  assert.equal(isApproximateArea('approximate'), true);
  assert.equal(isApproximateArea('landmark'), false);
  assert.equal(isApproximateArea('region'), false);
  assert.equal(approximateAreaLabel(2000), 'Approximate area (uncertainty ~2.0 km)');
  assert.equal(approximateAreaLabel(5500), 'Approximate area (uncertainty ~5.5 km)');
});

test('uncertainty circle is drawn only for approximate area with a positive radius', () => {
  assert.equal(shouldDrawUncertaintyCircle('approximate', 2000), true);
  assert.equal(shouldDrawUncertaintyCircle('approximate', 0), false);
  assert.equal(shouldDrawUncertaintyCircle('approximate', -1), false);
  assert.equal(shouldDrawUncertaintyCircle('approximate', null), false);
  assert.equal(shouldDrawUncertaintyCircle('landmark', 100), false);
  assert.equal(shouldDrawUncertaintyCircle('region', 5000), false);
});
