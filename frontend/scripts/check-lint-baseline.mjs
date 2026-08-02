import { readFile } from 'node:fs/promises'
import { ESLint } from 'eslint'

const baselineUrl = new URL('../eslint-baseline.json', import.meta.url)
const baseline = JSON.parse(await readFile(baselineUrl, 'utf8'))
const maxWarnings = Number.isInteger(baseline.maxWarnings) ? baseline.maxWarnings : 0

const eslint = new ESLint({ cwd: process.cwd() })
const results = await eslint.lintFiles(['.'])
const formatter = await eslint.loadFormatter('stylish')
const formatted = formatter.format(results)
if (formatted) {
  process.stdout.write(formatted)
}

const errors = results.reduce((total, result) => total + result.errorCount, 0)
const warnings = results.reduce((total, result) => total + result.warningCount, 0)

if (errors > 0) {
  process.stderr.write(`ESLint failed with ${errors} error(s).\n`)
  process.exit(1)
}

if (warnings > maxWarnings) {
  process.stderr.write(
    `ESLint warning baseline exceeded: ${warnings} warning(s), maximum ${maxWarnings}.\n`,
  )
  process.exit(1)
}

process.stdout.write(`ESLint warnings: ${warnings}/${maxWarnings}\n`)
