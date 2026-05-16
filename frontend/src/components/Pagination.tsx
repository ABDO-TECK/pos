// @ts-nocheck
import React from 'react'
import styles from './Pagination.module.css'

export default function Pagination({ current, total, onPage }) {
  if (total <= 1) return null;

  return (
    <div className={styles.wrapper}>
      <button 
        className="btn btn-ghost btn-sm" 
        onClick={() => onPage(current - 1)} 
        disabled={current <= 1}
      >
        السابق
      </button>
      
      <span className={styles.pageInfo}>
        صفحة {current} من {total}
      </span>
      
      <button 
        className="btn btn-ghost btn-sm" 
        onClick={() => onPage(current + 1)} 
        disabled={current >= total}
      >
        التالي
      </button>
    </div>
  )
}
