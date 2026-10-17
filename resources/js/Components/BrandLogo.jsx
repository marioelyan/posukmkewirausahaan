import React from "react";
import { BRAND } from "@/config/brand";

/**
 * BrandMark — kotak mark platform.
 *
 * - `logoSrc` diisi (mis. logo toko dari Settings → Store) → render <img>.
 * - `label` diisi (mis. inisial nama toko) → huruf di dalam kotak gradient.
 * - Tanpa keduanya → brand mark platform (BRAND.mark, `public/images/icon.svg`).
 *
 * Urutannya sengaja: logo/nama toko selalu menang atas brand platform.
 */
export function BrandMark({
    size = 40,
    logoSrc = null,
    label = null,
    rounded = "rounded-xl",
    className = "",
    alt = null,
}) {
    // `label` dihormati lebih dulu: kalau toko punya nama tapi belum punya logo,
    // inisial nama toko yang tampil — bukan brand platform.
    const src = logoSrc || (label ? null : BRAND.mark);

    if (src) {
        return (
            <img
                src={src}
                alt={alt || label || BRAND.name}
                width={size}
                height={size}
                style={{ width: size, height: size }}
                className={`shrink-0 object-cover ${rounded} ${className}`}
            />
        );
    }

    return (
        <div
            style={{ width: size, height: size }}
            className={`flex shrink-0 items-center justify-center bg-gradient-to-br from-primary-500 to-primary-700 text-white font-bold ${rounded} ${className}`}
            aria-label={alt || label || BRAND.name}
        >
            <span style={{ fontSize: Math.round(size * 0.4) }}>{label}</span>
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
