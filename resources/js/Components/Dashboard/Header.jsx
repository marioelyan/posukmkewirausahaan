import React from 'react'

export default function Header({ children, title, subtitle }) {
    return (
        <div className='flex w-full flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4'>
            <div className='min-w-0'>
                <h1 className='text-lg font-bold capitalize text-slate-900 dark:text-white truncate'>{title}</h1>
                {subtitle && (
                    <p className='text-sm text-slate-500 dark:text-slate-400'>{subtitle}</p>
                )}
            </div>
            {children && (
                <div className='flex items-center gap-2 flex-wrap'>{children}</div>
            )}
        </div>
    )
}
