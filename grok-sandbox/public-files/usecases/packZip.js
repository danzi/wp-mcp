const fs = require('fs')
const path = require('path')
const { spawnSync } = require('child_process')
const { fail, success } = require('../response')
const { logDebug } = require('../logger')

async function packZip(folderArg) {
  if (!folderArg) {
    fail('folder argument required')
  }
  const folderAbs = path.resolve(folderArg)
  if (!fs.existsSync(folderAbs) || !fs.statSync(folderAbs).isDirectory()) {
    fail('folder not found', { folderAbs })
  }
  const postJson = path.join(folderAbs, 'post.json')
  const imagesDir = path.join(folderAbs, 'images')
  if (!fs.existsSync(postJson)) {
    fail('post.json missing', { postJson })
  }
  if (!fs.existsSync(imagesDir)) {
    fail('images folder missing', { imagesDir })
  }

  const slug = path.basename(folderAbs)
  const parentAbs = path.dirname(folderAbs)
  const zipAbs = path.join(folderAbs, `${slug}.zip`)
  if (fs.existsSync(zipAbs)) {
    fs.unlinkSync(zipAbs)
  }

  const result = spawnSync(
    'zip',
    ['-r', zipAbs, slug, '-x', '*.DS_Store', '-x', `${slug}/*.zip`, '-x', `${slug}/**/*.zip`],
    {
      cwd: parentAbs,
      encoding: 'utf8',
    }
  )
  if (result.status !== 0) {
    fail('zip command failed', {
      status: result.status,
      stderr: result.stderr,
      stdout: result.stdout,
    })
  }
  if (!fs.existsSync(zipAbs)) {
    fail('zip file was not created', { zipAbs })
  }
  logDebug('zip packed', { zipAbs, bytes: fs.statSync(zipAbs).size })
  return success({
    zipAbs,
    zipName: `${slug}.zip`,
    bytes: fs.statSync(zipAbs).size,
  })
}

module.exports = {
  packZip,
}
