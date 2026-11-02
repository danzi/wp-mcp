const { loadEnv } = require('./loadEnv')
const { fail, failPayload, printJson } = require('./response')
const { logDebug } = require('./logger')
const { startStaticServer } = require('./interactors/staticServer')

async function main() {
  loadEnv()
  const folder = process.argv[2]
  if (!folder) {
    fail('serve.js needs folder argument')
  }
  const host = process.env.STATIC_HOST
  const port = process.env.STATIC_PORT
  await startStaticServer(folder, host, port)
  logDebug('serve ready', { folder, host, port })
}

main().catch((error) => {
  printJson(failPayload(error.message, error.extra || null))
  process.exit(1)
})
