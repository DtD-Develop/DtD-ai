'use client'
import React, { useState } from 'react'
import axios from 'axios'

export default function TrainPage() {
  const [status, setStatus] = useState('')

  async function startTrain() {
    setStatus('Queuing training job...')
    try {
      const res = await axios.post(`${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/train`, { mode: 'rag' })
      setStatus(JSON.stringify(res.data))
    } catch (e) {
      setStatus('Error: ' + (e.message || e))
    }
  }

  return (
    <div>
      <button onClick={startTrain}>Start Train (RAG)</button>
      <div style={{ marginTop: 12 }}>{status}</div>
    </div>
  )
}
