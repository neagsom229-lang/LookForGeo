/**
 * Display rules for area-level (approximate) answers.
 *
 * An approximate answer is never shown as a precise pin: it is labelled
 * "Approximate area" and drawn as an uncertainty circle only when a positive
 * radius is available.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LocationPrecision = factory();
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function isApproximateArea(locationPrecision) {
        return locationPrecision === 'approximate';
    }

    function shouldDrawUncertaintyCircle(locationPrecision, uncertaintyRadiusM) {
        const radius = Number(uncertaintyRadiusM ?? 0);
        return isApproximateArea(locationPrecision) && Number.isFinite(radius) && radius > 0;
    }

    function approximateAreaLabel(uncertaintyRadiusM) {
        const radiusKm = Number(uncertaintyRadiusM ?? 0) / 1000;
        return 'Approximate area (uncertainty ~' + radiusKm.toFixed(1) + ' km)';
    }

    return {
        isApproximateArea,
        shouldDrawUncertaintyCircle,
        approximateAreaLabel
    };
});
