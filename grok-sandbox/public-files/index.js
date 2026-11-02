const { loadEnv } = require('./loadEnv')
const { fail, failPayload, printJson } = require('./response')
const { startPublicFolder } = require('./usecases/startPublicFolder')
const { stopPublicFolder } = require('./usecases/stopPublicFolder')
const { packZip } = require('./usecases/packZip')

async function main() {
  loadEnv()
  const command = process.argv[2]
  if (command === 'start') {
    const folder = process.argv[3]
    const result = await startPublicFolder(folder)
    printJson(result)
    return
  }
  if (command === 'stop') {
    const result = await stopPublicFolder()
    printJson(result)
    return
  }
  if (command === 'pack') {
    const folder = process.argv[3]
    const result = await packZip(folder)
    printJson(result)
    return
  }
  fail('usage: node public-files/index.js start <folder> | stop | pack <folder>')
}

main().catch((error) => {
  printJson(failPayload(error.message, error.extra || null))
  process.exit(1)
})
