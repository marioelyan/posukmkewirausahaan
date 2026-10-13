import React from "react";
import { IconLetterK } from "@tabler/icons-react";
import { BRAND } from "@/config/brand";

/**
 * BrandMark — kotak mark platform (placeholder, mudah ditukar nanti).
 *
 * - `logoSrc` diisi (mis. logo toko dari Settings → Store) → render <img>.
 * - `label` diisi (mis. inisial nama toko) → huruf di dalam kotak gradient.
 * - Tanpa keduanya → glyph brand default (huruf "K").
 */
export function BrandMark({
    size = 40,
    logoSrc = null,
    label = null,
    rounded = "rounded-xl",
    className = "",
    alt = null,
    variant = "solid",
}) {
    if (logoSrc) {
        return (
            <img
                src={logoSrc}
                alt={alt || label || BRAND.name}
                width={size}
                height={size}
                style={{ width: size, height: size }}
                className={`shrink-0 object-cover ${rounded} ${className}`}
            />
        );
    }

    const glyph = label ? (
        <span style={{ fontSize: Math.round(size * 0.4) }}>{label}</span>
    ) : (
        <IconLetterK size={Math.round(size * 0.6)} strokeWidth={2.2} />
    );

    if (variant === "plain") {
        return (
            <span
                style={{ width: size, height: size }}
                className={`flex shrink-0 items-center justify-center ${className}`}
                aria-label={alt || label || BRAND.name}
            >
                {glyph}
            </span>
        );
    }

    return (
        <div
            style={{ width: size, height: size }}
            className={`flex shrink-0 items-center justify-center bg-gradient-to-br from-primary-500 to-primary-700 text-white font-bold ${rounded} ${className}`}
            aria-label={alt || label || BRAND.name}
        >
            {glyph}
        </div>
    );
}

/**
 * BrandLogo — mark + wordmark.
 *
 * Dipakai di app shell dan halaman publik. Selalu hormati logo/nama toko
 * (store profile) bila tersedia; jika tidak, jatuh ke brand platform.
 */
export default function BrandLogo({
    logoSrc = null,
    label = null,
    name = BRAND.name,
    tagline = null,
    showName = true,
    size = 40,
    rounded = "rounded-xl",
    className = "",
    textClassName = "",
    nameClassName = "",
    align = "center",
}) {
    return (
        <div
            className={`flex min-w-0 items-center gap-2.5 ${
                align === "center" ? "items-center" : "items-start"
            } ${className}`}
        >
            <BrandMark
                size={size}
                logoSrc={logoSrc}
                label={label}
                rounded={rounded}
                alt={name}
            />
            {showName && name ? (
                <div className={`min-w-0 ${textClassName}`}>
                    <span
                        className={`block truncate font-bold text-slate-800 dark:text-white ${nameClassName}`}
                    >
                        {name}
                    </span>
                    {tagline ? (
                        <span className="block truncate text-[10px] font-medium text-slate-400 dark:text-slate-500">
                            {tagline}
                        </span>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
