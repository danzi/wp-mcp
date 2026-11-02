const { spawn } = require('child_process')
const http = require('http')
const fs = require('fs')
const path = require('path')
const { fail } = require('../response')
const { logDebug } = require('../logger')
const { ngrokApiOrigin } = require('../loadEnv')
const { ensureRuntimeDir, stateDir } = require('./runtimeState')

function getJson(url) {
  return new Promise((resolve, reject) => {
    const req = http.get(url, (res) => {
      let body = ''
      res.setEncoding('utf8')
      res.on('data', (chunk) => {
        body += chunk
      })
      res.on('end', () => {
        if (res.statusCode !== 200) {
          reject(new Error(`ngrok api ${res.statusCode}: ${body}`))
          return
        }
        try {
          resolve(JSON.parse(body))
        } catch (error) {
          reject(error)
        }
      })
    })
    req.on('error', reject)
    req.setTimeout(2000, () => {
      req.destroy(new Error('ngrok api timeout'))
    })
  })
}

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms)
  })
}

async function waitPublicUrl(timeoutMs) {
  const api = `${ngrokApiOrigin()}/api/tunnels`
  const started = Date.now()
  let lastError = null
  while (Date.now() - started < timeoutMs) {
    try {
      const data = await getJson(api)
      const httpsTunnel = (data.tunnels || []).find(
        (tunnel) => tunnel.public_url && tunnel.public_url.startsWith('https://')
      )
      if (httpsTunnel) {
        logDebug('ngrok tunnel', httpsTunnel.public_url)
        return httpsTunnel.public_url.replace(/\/$/, '')
      }
    } catch (error) {
      lastError = error
      logDebug('ngrok api wait', error.message)
    }
    await sleep(400)
  }
  fail('ngrok public url not ready', { lastError: lastError ? lastError.message : null })
}

function startNgrok(staticOrigin) {
  ensureRuntimeDir()
  const logFile = path.join(stateDir(), 'ngrok.log')
  const out = fs.openSync(logFile, 'w')
  const child = spawn(
    process.env.NGROK_BIN,
    ['http', staticOrigin, '--log=stdout'],
    {
      detached: true,
      stdio: ['ignore', out, out],
    }
  )
  child.unref()
  if (!child.pid) {
    fail('ngrok did not start')
  }
  logDebug('ngrok pid', child.pid)
  return {
    pid: child.pid,
    logFile,
  }
}

function startServeProcess(folderAbs) {
  ensureRuntimeDir()
  const logFile = path.join(stateDir(), 'serve.log')
  const out = fs.openSync(logFile, 'w')
  const child = spawn(process.execPath, [path.join(__dirname, '..', 'serve.js'), folderAbs], {
    detached: true,
    stdio: ['ignore', out, out],
    cwd: path.join(__dirname, '..', '..'),
    env: process.env,
  })
  child.unref()
  if (!child.pid) {
    fail('static server did not start')
  }
  logDebug('serve pid', child.pid)
  return {
    pid: child.pid,
    logFile,
  }
}

function stopPid(pid) {
  if (!pid) {
    return
  }
  try {
    process.kill(pid, 'SIGTERM')
  } catch (error) {
    logDebug('kill skip', { pid, message: error.message })
  }
}

module.exports = {
  waitPublicUrl,
  startNgrok,
  startServeProcess,
  stopPid,
}
