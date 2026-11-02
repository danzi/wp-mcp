const http = require('http')
const fs = require('fs')
const path = require('path')
const { fail } = require('../response')
const { logDebug } = require('../logger')

const MIME = {
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.gif': 'image/gif',
  '.zip': 'application/zip',
}

function resolveSafe(rootDir, urlPath) {
  const decoded = decodeURIComponent((urlPath || '/').split('?')[0])
  const abs = path.normalize(path.join(rootDir, decoded))
  const root = path.resolve(rootDir)
  if (abs !== root && !abs.startsWith(`${root}${path.sep}`)) {
    fail('path traversal blocked', { urlPath })
  }
  return abs
}

function startStaticServer(rootDir, host, port) {
  const root = path.resolve(rootDir)
  if (!fs.existsSync(root) || !fs.statSync(root).isDirectory()) {
    fail('folder does not exist', { rootDir })
  }

  const server = http.createServer((req, res) => {
    try {
      const filePath = resolveSafe(root, req.url)
      if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
        res.writeHead(404, { 'Content-Type': 'text/plain' })
        res.end('not found')
        return
      }
      const ext = path.extname(filePath).toLowerCase()
      const type = MIME[ext] || 'application/octet-stream'
      res.writeHead(200, { 'Content-Type': type })
      fs.createReadStream(filePath).pipe(res)
    } catch (error) {
      logDebug('static serve error', error.message)
      res.writeHead(400, { 'Content-Type': 'text/plain' })
      res.end('bad request')
    }
  })

  return new Promise((resolve, reject) => {
    server.once('error', (error) => {
      reject(error)
    })
    server.listen(Number(port), host, () => {
      logDebug('static server listen', { host, port, root })
      resolve(server)
    })
  })
}

module.exports = {
  startStaticServer,
}
