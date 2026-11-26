/**
 * Honest heading for the evidence block.
 *
 * "CONFIRMED LOCATION EVIDENCE" is only allowed when the calibrated score
 * clears the "High" threshold (65), a valid pin exists, and the result is
 * not flagged low_confidence. Everything else is a possible location.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ConfidenceHeading = factory();
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    // Matches the calculator's "High" band (>= 65). Medium and below are
    // never presented as confirmed, even when the numeric score looks okay.
    const CONFIRMED_HEADING_MIN = 65;
    const CONFIRMED_LABEL = 'CONFIRMED LOCATION EVIDENCE';
    const POSSIBLE_LABEL = 'Possible location (low evidence)';

    function evidenceHeading(confidence, hasCoordinates, lowConfidence) {
        const score = Number(confidence);
        const confirmed = hasCoordinates === true
            && lowConfidence !== true
            && Number.isFinite(score)
            && score >= CONFIRMED_HEADING_MIN;

        return confirmed ? CONFIRMED_LABEL : POSSIBLE_LABEL;
    }

    return {
        CONFIRMED_HEADING_MIN,
        CONFIRMED_LABEL,
        POSSIBLE_LABEL,
        evidenceHeading
    };
});
