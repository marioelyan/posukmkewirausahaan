import React from "react";

/**
 * Skeleton - Base skeleton loading component
 * Usage: <Skeleton className="h-4 w-32" />
 */
export function Skeleton({ className = "", ...props }) {
    return (
        <div
            className={`animate-pulse bg-slate-200 dark:bg-slate-700 rounded ${className}`}
            {...props}
        />
    );
}

/**
 * SkeletonText - Text placeholder
 */
export function SkeletonText({ lines = 1, className = "" }) {
    return (
        <div className={`space-y-2 ${className}`}>
            {Array.from({ length: lines }).map((_, i) => (
                <Skeleton
                    key={i}
                    className={`h-4 ${
                        i === lines - 1 && lines > 1 ? "w-3/4" : "w-full"
                    }`}
                />
            ))}
        </div>
    );
}

/**
 * SkeletonCard - Card placeholder for grid layouts
 */
export function SkeletonCard({ hasImage = true, className = "" }) {
    return (
        <div
            className={`bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden ${className}`}
        >
            {hasImage && <Skeleton className="aspect-square w-full" />}
            <div className="p-4 space-y-3">
                <Skeleton className="h-5 w-3/4" />
                <Skeleton className="h-4 w-1/2" />
                <div className="flex justify-between items-center pt-2">
                    <Skeleton className="h-6 w-20" />
                    <Skeleton className="h-8 w-8 rounded-lg" />
                </div>
            </div>
        </div>
    );
}

/**
 * SkeletonTable - Table rows placeholder
 */
export function SkeletonTable({ rows = 5, cols = 4, className = "" }) {
    return (
        <div
            className={`bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden ${className}`}
        >
            {/* Header */}
            <div className="flex gap-4 p-4 border-b border-slate-100 dark:border-slate-800">
                {Array.from({ length: cols }).map((_, i) => (
                    <Skeleton key={i} className="h-4 flex-1" />
                ))}
            </div>
            {/* Rows */}
            {Array.from({ length: rows }).map((_, rowIndex) => (
                <div
                    key={rowIndex}
                    className="flex gap-4 p-4 border-b border-slate-50 dark:border-slate-800/50 last:border-0"
                >
                    {Array.from({ length: cols }).map((_, colIndex) => (
                        <Skeleton key={colIndex} className="h-4 flex-1" />
                    ))}
                </div>
            ))}
        </div>
    );
}

/**
 * SkeletonProductGrid - Product grid placeholder
 */
export function SkeletonProductGrid({ count = 8, className = "" }) {
    return (
        <div
            className={`grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 ${className}`}
        >
            {Array.from({ length: count }).map((_, i) => (
                <SkeletonCard key={i} hasImage />
            ))}
        </div>
    );
}

/**
 * SkeletonStats - Dashboard stats placeholder
 */
export function SkeletonStats({ count = 4, className = "" }) {
    return (
        <div
            className={`grid gap-4 sm:grid-cols-2 xl:grid-cols-4 ${className}`}
        >
            {Array.from({ length: count }).map((_, i) => (
                <div
                    key={i}
                    className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5"
                >
                    <div className="flex items-center gap-3 mb-4">
                        <Skeleton className="w-10 h-10 rounded-xl" />
                        <Skeleton className="h-4 w-20" />
                    </div>
                    <Skeleton className="h-8 w-24 mb-2" />
                    <Skeleton className="h-3 w-16" />
                </div>
            ))}
        </div>
    );
}

/**
 * SkeletonList - List items placeholder
 */
export function SkeletonList({ count = 5, className = "" }) {
    return (
        <div className={`space-y-3 ${className}`}>
            {Array.from({ length: count }).map((_, i) => (
                <div
                    key={i}
                    className="flex items-center gap-3 p-3 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800"
                >
                    <Skeleton className="w-10 h-10 rounded-full" />
                    <div className="flex-1 space-y-2">
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-3 w-1/2" />
                    </div>
                    <Skeleton className="h-6 w-16 rounded-full" />
                </div>
            ))}
        </div>
    );
}

/**
 * SkeletonAvatar - Avatar placeholder
 */
export function SkeletonAvatar({ size = "md", className = "" }) {
    const sizes = {
        sm: "w-8 h-8",
        md: "w-10 h-10",
        lg: "w-12 h-12",
        xl: "w-16 h-16",
    };

    return <Skeleton className={`rounded-full ${sizes[size]} ${className}`} />;
}

// Default export all
export default {
    Base: Skeleton,
    Text: SkeletonText,
    Card: SkeletonCard,
    Table: SkeletonTable,
    ProductGrid: SkeletonProductGrid,
    Stats: SkeletonStats,
    List: SkeletonList,
    Avatar: SkeletonAvatar,
};
