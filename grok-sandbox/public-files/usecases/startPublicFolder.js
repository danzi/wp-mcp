const fs = require('fs')
const path = require('path')
const http = require('http')
const { fail, success } = require('../response')
const { logDebug } = require('../logger')
const { staticOrigin } = require('../loadEnv')
const { listImageFiles, listZipFiles } = require('../interactors/listImageFiles')
const { readState, writeState } = require('../interactors/runtimeState')
const {
  startNgrok,
  startServeProcess,
  waitPublicUrl,
  stopPid,
} = require('../interactors/ngrokTunnel')
const { stopPublicFolder } = require('./stopPublicFolder')

function waitLocal(origin, timeoutMs) {
  const started = Date.now()
  return new Promise((resolve, reject) => {
    const tryOnce = () => {
      const req = http.get(`${origin}/`, () => {
        resolve()
      })
      req.on('error', () => {
        if (Date.now() - started > timeoutMs) {
          reject(new Error('static server not listening'))
          return
        }
        setTimeout(tryOnce, 200)
      })
      req.setTimeout(500, () => {
        req.destroy()
      })
    }
    tryOnce()
  })
}

async function startPublicFolder(folderArg) {
  if (!folderArg) {
    fail('folder argument required')
  }
  const folderAbs = path.resolve(folderArg)
  if (!fs.existsSync(folderAbs) || !fs.statSync(folderAbs).isDirectory()) {
    fail('folder not found', { folderAbs })
  }

  const existing = readState()
  if (existing) {
    logDebug('stopping leftover tunnel before start')
    await stopPublicFolder()
  }

  const files = listImageFiles(folderAbs)
  const zipFiles = listZipFiles(folderAbs)
  const origin = staticOrigin()
  const serve = startServeProcess(folderAbs)
  let ngrok = null
  try {
    await waitLocal(origin, 8000)
    ngrok = startNgrok(origin)
    const publicBaseUrl = await waitPublicUrl(20000)
    const mapped = files.map((file) => ({
      name: file.name,
      absPath: file.absPath,
      url: `${publicBaseUrl}${file.relativeUrl}`,
    }))
    const mappedZips = zipFiles.map((file) => ({
      name: file.name,
      absPath: file.absPath,
      url: `${publicBaseUrl}${file.relativeUrl}`,
    }))
    const zipUrl = mappedZips.length ? mappedZips[0].url : null
    const state = {
      folderAbs,
      publicBaseUrl,
      staticOrigin: origin,
      servePid: serve.pid,
      ngrokPid: ngrok.pid,
      files: mapped,
      zips: mappedZips,
      zipUrl,
      startedAt: new Date().toISOString(),
    }
    writeState(state)
    logDebug('public folder ready', { publicBaseUrl, count: mapped.length, zipUrl })
    return success({
      publicBaseUrl,
      staticOrigin: origin,
      files: mapped,
      zips: mappedZips,
      zipUrl,
    })
  } catch (error) {
    if (ngrok && ngrok.pid) {
      stopPid(ngrok.pid)
    }
    stopPid(serve.pid)
    throw error
  }
}

module.exports = {
  startPublicFolder,
}
