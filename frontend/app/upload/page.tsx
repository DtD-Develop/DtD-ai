'use client'
import React, { useState } from 'react'
import axios from 'axios'

export default function UploadPage() {
  const [file, setFile] = useState(null)
  const [status, setStatus] = useState('')

  async function submit() {
    if (!file) return
    const fd = new FormData()
    fd.append('file', file)
    setStatus('Uploading...')
    try {
      const res = await axios.post(`${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/upload`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      setStatus(JSON.stringify(res.data))
    } catch (e) {
      setStatus('Error: ' + (e.message || e))
    }
  }

  return (
    <div>
      <input type="file" onChange={e => setFile(e.target.files?.[0] || null)} />
      <button onClick={submit} style={{ marginLeft: 8 }}>Upload</button>
      <div style={{ marginTop: 12 }}>{status}</div>
    </div>
  )
}
