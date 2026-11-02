const fs = require('fs')
const path = require('path')
const { fail } = require('./response')
const { logDebug } = require('./logger')

function loadEnv() {
  const envPath = path.join(__dirname, '..', '.env')
  if (!fs.existsSync(envPath)) {
    fail('.env not found', { envPath })
  }

  const raw = fs.readFileSync(envPath, 'utf8')
  raw.split('\n').forEach((line) => {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) {
      return
    }
    const eq = trimmed.indexOf('=')
    if (eq <= 0) {
      return
    }
    const key = trimmed.slice(0, eq).trim()
    const value = trimmed.slice(eq + 1).trim()
    if (process.env[key] === undefined) {
      process.env[key] = value
    }
  })

  const required = [
    'DEBUG',
    'NEWS_DIR',
    'STATIC_HOST',
    'STATIC_PORT',
    'NGROK_BIN',
    'NGROK_API_HOST',
    'NGROK_API_PORT',
  ]
  required.forEach((key) => {
    if (!process.env[key]) {
      fail(`missing env ${key}`, { envPath })
    }
  })

  logDebug('env loaded', { envPath })
}

function staticOrigin() {
  return `http://${process.env.STATIC_HOST}:${process.env.STATIC_PORT}`
}

function ngrokApiOrigin() {
  return `http://${process.env.NGROK_API_HOST}:${process.env.NGROK_API_PORT}`
}

module.exports = {
  loadEnv,
  staticOrigin,
  ngrokApiOrigin,
}
