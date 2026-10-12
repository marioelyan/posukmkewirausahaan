import { useForm } from '@inertiajs/react';
import { IconSearch } from '@tabler/icons-react';
import React from 'react'
export default function Search({ url, placeholder }) {

    // keep the current term visible after Inertia navigations
    const initialSearch =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('search') || ''
            : '';

    // define use form inertia
    const { data, setData, get } = useForm({
        search: initialSearch,
    })

    // define method searchData
    const searchData = (e) => {
        e.preventDefault();

        get(`${url}?search=${data.search}`)
    }

    return (
        <form onSubmit={searchData} role="search">
            <div className='relative'>
                <input
                    type='search'
                    value={data.search}
                    onChange={e => setData('search', e.target.value)}
                    aria-label={placeholder || 'Cari'}
                    className='w-full h-11 py-2 px-4 pr-11 text-sm rounded-xl border transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 text-slate-800 bg-white border-slate-200 placeholder-slate-400 dark:text-slate-200 dark:bg-slate-900 dark:border-slate-700 dark:placeholder-slate-500'
                    placeholder={placeholder} />
                <div className='absolute inset-y-0 right-0 flex items-center pointer-events-none pr-4'>
                    <IconSearch className='text-slate-400 w-5 h-5' />
                </div>
            </div>
        </form>
    )
}
