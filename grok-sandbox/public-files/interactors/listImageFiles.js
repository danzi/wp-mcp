const fs = require('fs')
const path = require('path')
const { fail } = require('../response')

const IMAGE_EXT = new Set(['.jpg', '.jpeg', '.png', '.webp', '.gif'])

function listImageFiles(folderAbs) {
  const imagesDir = path.join(folderAbs, 'images')
  if (!fs.existsSync(imagesDir) || !fs.statSync(imagesDir).isDirectory()) {
    fail('images folder not found', { imagesDir })
  }

  const names = fs
    .readdirSync(imagesDir)
    .filter((name) => IMAGE_EXT.has(path.extname(name).toLowerCase()))
    .filter((name) => fs.statSync(path.join(imagesDir, name)).isFile())
    .sort()

  if (names.length === 0) {
    fail('no image files in images/', { imagesDir })
  }

  return names.map((name) => ({
    name,
    relativeUrl: `/images/${name}`,
    absPath: path.join(imagesDir, name),
  }))
}

function listZipFiles(folderAbs) {
  const names = fs
    .readdirSync(folderAbs)
    .filter((name) => path.extname(name).toLowerCase() === '.zip')
    .filter((name) => fs.statSync(path.join(folderAbs, name)).isFile())
    .sort()

  return names.map((name) => ({
    name,
    relativeUrl: `/${name}`,
    absPath: path.join(folderAbs, name),
  }))
}

module.exports = {
  listImageFiles,
  listZipFiles,
}
