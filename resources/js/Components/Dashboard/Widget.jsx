import React from 'react'

export default function Widget({ title, icon, subtitle, className, total, color }) {
    return (
        <div className={`${className} border border-slate-200 dark:border-slate-800 p-4 rounded-2xl bg-white dark:bg-slate-900 transition-shadow hover:shadow-md`}>
            <div className='flex justify-between items-center gap-4'>
                <div className='flex items-center gap-3 min-w-0'>
                    <div className={`p-2 rounded-xl shrink-0 ${color}`}>
                        {icon}
                    </div>
                    <div className='flex flex-col min-w-0'>
                        <div className='font-semibold text-slate-900 dark:text-slate-100 truncate'>{title}</div>
                        <div className='text-xs text-slate-500 dark:text-slate-400 truncate'>{subtitle}</div>
                    </div>
                </div>
                <div className='font-semibold text-base font-mono p-2 text-slate-900 dark:text-white shrink-0'>
                    {total}
                </div>
            </div>
        </div>
    )
}
