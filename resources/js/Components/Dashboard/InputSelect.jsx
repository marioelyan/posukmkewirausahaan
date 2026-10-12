import React, { useState, useId } from 'react'
import { Listbox } from '@headlessui/react'
import { IconChevronDown, IconCircle, IconCircleFilled } from '@tabler/icons-react'

export default function InputSelect({ selected, data, setSelected, label, errors, placeholder, multiple = false, searchable = false, displayKey = 'name', id }) {
    const [search, setSearch] = useState('')
    const generatedId = useId()
    const buttonId = id || generatedId

    const filteredData = data.filter(item =>
        item[displayKey]?.toLowerCase().includes(search.toLowerCase())
    )

    return (
        <div className='flex flex-col gap-2'>
            {label && (
                <label htmlFor={buttonId} className='text-sm font-medium text-slate-700 dark:text-slate-300'>{label}</label>
            )}
            <Listbox value={selected} onChange={setSelected} multiple={multiple} by="id">
                <Listbox.Button id={buttonId} className={'w-full h-11 px-4 border text-sm rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 flex justify-between items-center gap-8 bg-white text-slate-800 focus:border-primary-500 border-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:border-slate-700'}>
                    <span className='truncate text-left'>
                        {multiple ? (
                            selected.length > 0 ? selected.map(item => item[displayKey]).join(', ') : <span className='text-slate-400'>{placeholder}</span>
                        ) : (
                            selected ? selected[displayKey] : <span className='text-slate-400'>{placeholder}</span>
                        )}
                    </span>
                    <IconChevronDown size={20} strokeWidth={1.5} className='shrink-0 text-slate-400' />
                </Listbox.Button>
                <Listbox.Options className={'mt-1 p-2 border rounded-xl flex flex-col gap-1 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900 border-slate-200 focus:outline-none max-h-64 overflow-y-auto'}>
                    {searchable && (
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari..."
                            className="w-full px-3 py-2 mb-2 text-sm border rounded-lg bg-white text-slate-700 border-slate-200 focus:outline-none focus:border-primary-500 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700"
                        />
                    )}
                    {filteredData.map((item) => (
                        <Listbox.Option key={item.id} value={item}>
                            {({ selected }) => (
                                <div
                                    className={`text-sm cursor-pointer px-3 py-2 rounded-lg flex items-center gap-2 ${selected ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/50 dark:text-primary-300' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'}`}>
                                    {selected ? <IconCircleFilled size={15} strokeWidth={1.5} className='text-primary-500' /> : <IconCircle size={15} strokeWidth={1.5} className='text-slate-300 dark:text-slate-600' />}
                                    {item[displayKey]}
                                </div>
                            )}
                        </Listbox.Option>
                    ))}
                </Listbox.Options>
            </Listbox>
            {errors && (
                <small className='text-xs text-danger-500 dark:text-danger-400'>{errors}</small>
            )}
        </div>
    )
}
