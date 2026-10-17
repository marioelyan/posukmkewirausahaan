import defaultTheme from "tailwindcss/defaultTheme";
import forms from "@tailwindcss/forms";

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
        "./storage/framework/views/*.php",
        "./resources/views/**/*.blade.php",
        "./resources/js/**/*.jsx",
    ],
    darkMode: "class",
    theme: {
        extend: {
            fontFamily: {
                sans: [
                    "Inter",
                    "Plus Jakarta Sans",
                    ...defaultTheme.fontFamily.sans,
                ],
                mono: [
                    "JetBrains Mono",
                    "Fira Code",
                    ...defaultTheme.fontFamily.mono,
                ],
            },
            colors: {
                // Primary - KasirKwu Navy (#21416D)
                primary: {
                    50: "#f3f7fc",
                    100: "#e4ecf7",
                    200: "#c5d6ec",
                    300: "#9bb8dc",
                    400: "#6e93c6",
                    500: "#456faf",
                    600: "#21416d",
                    700: "#1b3559",
                    800: "#152a47",
                    900: "#102138",
                    950: "#081526",
                },
                // Accent - Analog Blue (#2E7CB8, harmonized with primary)
                accent: {
                    50: "#f0f7fc",
                    100: "#dcedf8",
                    200: "#b7d9f0",
                    300: "#86bfe4",
                    400: "#559fd4",
                    500: "#2e7cb8",
                    600: "#25648f",
                    700: "#1f5578",
                    800: "#1b4864",
                    900: "#163a50",
                    950: "#0d2331",
                },
                // Success - Emerald (harmonized)
                success: {
                    50: "#ecfdf5",
                    100: "#d1fae5",
                    200: "#a7f3d0",
                    300: "#6ee7b7",
                    400: "#34d399",
                    500: "#1f9d6b",
                    600: "#17805a",
                    700: "#12664a",
                    800: "#0f5139",
                    900: "#0c412e",
                    950: "#052e1f",
                },
                // Warning - Amber (harmonized)
                warning: {
                    50: "#fffbeb",
                    100: "#fef3c7",
                    200: "#fde68a",
                    300: "#fcd34d",
                    400: "#f5b619",
                    500: "#d68910",
                    600: "#b36f0b",
                    700: "#8f590a",
                    800: "#74480d",
                    900: "#633d0f",
                    950: "#3a2306",
                },
                // Danger - Rose (harmonized)
                danger: {
                    50: "#fff1f2",
                    100: "#ffe4e6",
                    200: "#fecdd3",
                    300: "#fda4af",
                    400: "#f4707f",
                    500: "#c4384d",
                    600: "#a62d40",
                    700: "#892637",
                    800: "#722333",
                    900: "#62212f",
                    950: "#360e16",
                },
            },
            spacing: {
                18: "4.5rem",
                88: "22rem",
                100: "25rem",
                112: "28rem",
                128: "32rem",
            },
            minHeight: {
                touch: "2.75rem", // 44px - minimum touch target
                "touch-lg": "3rem", // 48px - comfortable touch target
            },
            minWidth: {
                touch: "2.75rem",
                "touch-lg": "3rem",
            },
            borderRadius: {
                "4xl": "2rem",
            },
            boxShadow: {
                glow: "0 0 20px rgba(33, 65, 109, 0.3)",
                "glow-lg": "0 0 40px rgba(33, 65, 109, 0.4)",
                "inner-lg": "inset 0 4px 6px -1px rgb(0 0 0 / 0.1)",
            },
            animation: {
                "slide-in": "slideIn 0.2s ease-out",
                "slide-up": "slideUp 0.2s ease-out",
                "fade-in": "fadeIn 0.15s ease-out",
                "pulse-subtle":
                    "pulseSubtle 2s cubic-bezier(0.4, 0, 0.6, 1) infinite",
                "bounce-subtle":
                    "bounceSubtle 0.5s cubic-bezier(0.34, 1.56, 0.64, 1)",
                "cart-add": "cartAdd 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)",
            },
            keyframes: {
                slideIn: {
                    "0%": { transform: "translateX(100%)", opacity: "0" },
                    "100%": { transform: "translateX(0)", opacity: "1" },
                },
                slideUp: {
                    "0%": { transform: "translateY(10px)", opacity: "0" },
                    "100%": { transform: "translateY(0)", opacity: "1" },
                },
                fadeIn: {
                    "0%": { opacity: "0" },
                    "100%": { opacity: "1" },
                },
                pulseSubtle: {
                    "0%, 100%": { opacity: "1" },
                    "50%": { opacity: "0.7" },
                },
                bounceSubtle: {
                    "0%": { transform: "scale(1)" },
                    "50%": { transform: "scale(1.05)" },
                    "100%": { transform: "scale(1)" },
                },
                cartAdd: {
                    "0%": { transform: "scale(0.8)", opacity: "0" },
                    "50%": { transform: "scale(1.1)" },
                    "100%": { transform: "scale(1)", opacity: "1" },
                },
            },
            backdropBlur: {
                xs: "2px",
            },
            transitionDuration: {
                250: "250ms",
            },
        },
    },
    plugins: [forms],
};
