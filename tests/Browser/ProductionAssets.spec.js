import {test,expect} from '@playwright/test';
test('compiled login page loads and renders without browser errors',async({page})=>{
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 const response=await page.goto('/login');expect(response.status()).toBe(200);
 await expect(page.getByRole('textbox',{name:'Email',exact:true})).toBeVisible();
 await expect(page.getByLabel('Password',{exact:true})).toBeVisible();
 expect(errors).toEqual([]);
});
