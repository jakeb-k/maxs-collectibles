import {defineConfig} from '@playwright/test';
export default defineConfig({workers:1,testDir:'../../tests',outputDir:process.env.MAXS_BROWSER_OUTPUT,use:{baseURL:process.env.MAXS_BROWSER_URL},reporter:[['list'],['json',{outputFile:process.env.MAXS_BROWSER_OUTPUT+'/results.json'}]]});
