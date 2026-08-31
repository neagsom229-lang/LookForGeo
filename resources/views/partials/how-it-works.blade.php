<!-- resources/views/partials/how-it-works.blade.php -->
<style>
.hiw-section {
    margin-bottom: 40px;
}

/* Header & Badge */
.hiw-header {
    text-align: center;
    margin-bottom: 50px;
    position: relative;
}

.hiw-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 100px;
    background: var(--accent-soft);
    border: 1px solid rgba(201, 138, 70, 0.25);
    color: var(--accent);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 16px;
    animation: fadeInUp 0.6s ease both;
}

.hiw-badge .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent);
}

.hiw-title {
    font-family: var(--font-display);
    font-size: clamp(30px, 4vw, 42px);
    font-weight: 600;
    line-height: 1.1;
    margin-bottom: 16px;
    animation: fadeInUp 0.6s 0.1s ease both;
}

.hiw-subtitle {
    color: var(--text-secondary);
    font-size: 16px;
    max-width: 600px;
    margin: 0 auto;
    line-height: 1.7;
    animation: fadeInUp 0.6s 0.2s ease both;
}

/* Steps Grid */
.hiw-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 60px;
}

.hiw-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 36px 28px;
    text-align: center;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    overflow: hidden;
}

/* Subtle glow effect on hover */
.hiw-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
    opacity: 0;
    transition: opacity 0.4s;
}

.hiw-card:hover {
    transform: translateY(-6px);
    border-color: rgba(201, 138, 70, 0.35);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
}

.hiw-card:hover::before {
    opacity: 1;
}

/* Entrances and staggering */
.hiw-card {
    animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.hiw-card:nth-child(1) {
    animation-delay: 0.2s;
}

.hiw-card:nth-child(2) {
    animation-delay: 0.3s;
}

.hiw-card:nth-child(3) {
    animation-delay: 0.4s;
}

/* Icons */
.hiw-icon {
    width: 68px;
    height: 68px;
    border-radius: 16px;
    background: var(--accent-soft);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin: 0 auto 22px;
    transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.4s;
}

.hiw-card:hover .hiw-icon {
    transform: scale(1.1) rotate(-6deg);
    background: rgba(201, 138, 70, 0.2);
}

.hiw-card h3 {
    font-family: var(--font-display);
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 12px;
}

.hiw-card p {
    font-size: 14px;
    color: var(--text-secondary);
    line-height: 1.7;
}

/* FAQ Accordion */
.hiw-faq {
    max-width: 800px;
    margin: 0 auto;
}

.hiw-faq h2 {
    font-family: var(--font-display);
    font-size: 28px;
    text-align: center;
    margin-bottom: 30px;
}

details {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 14px;
    overflow: hidden;
    transition: border-color 0.3s;
}

details:hover {
    border-color: rgba(201, 138, 70, 0.3);
}

summary {
    cursor: pointer;
    padding: 20px 24px;
    font-weight: 600;
    font-size: 15.5px;
    list-style: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: color 0.3s;
}

summary:hover {
    color: var(--accent);
}

summary::-webkit-details-marker {
    display: none;
}

summary::after {
    content: '\f078';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    color: var(--accent);
    font-size: 13px;
    transition: transform 0.3s ease;
}

details[open] summary::after {
    transform: rotate(180deg);
}

details p {
    padding: 0 24px 20px;
    color: var(--text-secondary);
    font-size: 14px;
    line-height: 1.7;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(24px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<div class="hiw-section">
    <!-- Header -->
    <div class="hiw-header">
        <div class="hiw-badge"><span class="dot"></span> How it works</div>
        <h2 class="hiw-title">How TraceGeo Works</h2>
        <p class="hiw-subtitle">Our AI-powered engine combines visual analysis, metadata extraction, and deep reasoning
            to uncover the exact location of any photo.</p>
    </div>

    <!-- 3 Step Cards (Client-Focused Copy) -->
    <div class="hiw-grid">
        <div class="hiw-card">
            <div class="hiw-icon"><i class="fas fa-magnifying-glass"></i></div>
            <h3>1. Visual Evidence Scan</h3>
            <p>Upload any photo. Our AI scans visual clues—landmarks, street signs, architecture, and even vegetation—to
                build a profile of where the photo was taken.</p>
        </div>
        <div class="hiw-card">
            <div class="hiw-icon"><i class="fas fa-location-dot"></i></div>
            <h3>2. Coordinate Recovery</h3>
            <p>The engine pinpoints exact latitude and longitude coordinates, coupled with a confidence score, letting
                you verify the accuracy of the result instantly.</p>
        </div>
        <div class="hiw-card">
            <div class="hiw-icon"><i class="fas fa-brain"></i></div>
            <h3>3. AI Reasoning</h3>
            <p>We don't just give you a dot on the map. TraceGeo explains its deduction, breaking down the visual
                evidence used to reach the conclusion.</p>
        </div>
    </div>

    <!-- FAQ Section (7 Comprehensive Questions) -->
    <div class="hiw-faq">
        <h2>Frequently Asked Questions</h2>

        <details>
            <summary>What is TraceGeo?</summary>
            <p>TraceGeo is an AI-powered geolocation tool. It analyzes the visual clues in a photo—like buildings,
                street signs, terrain, and lighting—to determine exactly where it was taken.</p>
        </details>

        <details>
            <summary>How accurate is the location?</summary>
            <p>Accuracy depends on the number of visible landmarks and metadata in the image. We provide a confidence
                score (0-100%) so you always know how reliable the result is.</p>
        </details>

        <details>
            <summary>How long does the analysis take?</summary>
            <p>Most photos are analyzed in under 30 seconds. Our system runs in the background, so you can watch the
                progress bar in real-time.</p>
        </details>

        <details>
            <summary>What image formats and sizes are supported?</summary>
            <p>We currently support JPG, PNG, GIF, and WebP files up to 20MB. For best results, use clear,
                high-resolution photos.</p>
        </details>

        <details>
            <summary>Is my image data stored or shared?</summary>
            <p>Images are uploaded to Cloudinary to generate temporary URLs for display. Your images are never shared
                publicly, and you can delete them from your history at any time.</p>
        </details>

        <details>
            <summary>Do I need an account or subscription?</summary>
            <p>Yes, creating a free account is required to run analyses. This allows us to save your history and ensure
                the AI isn't being abused by bots.</p>
        </details>

        <details>
            <summary>Can I use TraceGeo on my mobile phone?</summary>
            <p>Absolutely! TraceGeo is fully responsive and works perfectly on any mobile browser, tablet, or desktop.
            </p>
        </details>
    </div>
</div>