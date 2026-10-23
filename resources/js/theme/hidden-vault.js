/**
 * The Hidden Vault approved foundation, transcribed from the editable pack.
 *
 * This object is canonical data for application code. Tailwind v4 consumes the
 * matching CSS-first tokens in `resources/css/hidden-vault-tokens.css`; do not
 * pass this object to the legacy Tailwind configuration and create a second
 * source of truth.
 */
export const hiddenVaultTheme = {
    colors: {
        "vault-black": "#0D0C09",
        "antique-gold": "#C9A661",
        "warm-stone": "#E8DDC4",
        parchment: "#F8F4E9",
        "ink-stone": "#3E3A32",
        "coral-neon": "#FF4D6D",
        "violet-neon": "#A735FF",
        "teal-neon": "#00C2B8",
        jade: "#1F6F58",
        "amber-light": "#FFD67A",
        surface: {dark: "#0D0C09", light: "#F8F4E9", soft: "#E8DDC4", elevated: "#3E3A32"},
        text: {onDark: "#F8F4E9", onLight: "#0D0C09", muted: "#3E3A32"},
        action: {primary: "#C9A661", foreground: "#0D0C09", focus: "#C9A661"},
    },
    fontFamily: {
        display: ["Playfair Display", "Georgia", "serif"],
        ui: ["Inter", "Arial", "sans-serif"],
        annotation: ["Caveat", "cursive"],
    },
    fontSize: {
        "display-lg": ["64px", {lineHeight: "1.125"}],
        "display-md": ["40px", {lineHeight: "1.2"}],
        "display-sm": ["28px", {lineHeight: "1.29"}],
        "heading-sm": ["20px", {lineHeight: "1.4"}],
        body: ["16px", {lineHeight: "1.75"}],
        small: ["14px", {lineHeight: "1.57"}],
        caption: ["12px", {lineHeight: "1.66"}],
    },
    spacing: {"hv-1": "4px", "hv-2": "8px", "hv-4": "16px", "hv-6": "24px", "hv-8": "32px", "hv-12": "48px", "hv-16": "64px"},
    borderRadius: {"hv-sm": "4px", "hv-md": "8px", "hv-lg": "16px"},
    borderWidth: {"hv-card": "1px", "hv-focus": "2px"},
    boxShadow: {"hv-warm-glow": "0 0 18px rgba(201,166,97,0.18)", "hv-soft": "0 7px 24px rgba(13,12,9,0.1)"},
    transitionDuration: {"hv-quick": "200ms", "hv-standard": "300ms"},
    transitionTimingFunction: {"hv-gentle": "cubic-bezier(0.22,1,0.36,1)"},
    backgroundImage: {
        "hv-amber-glow": "radial-gradient(ellipse at 50% 0%, rgba(255,214,122,0.17), transparent 65%)",
        "hv-neon-reflection": "linear-gradient(115deg, rgba(167,53,255,0.09), rgba(0,194,184,0.08), rgba(255,77,109,0.09))",
    },
};

export default hiddenVaultTheme;
