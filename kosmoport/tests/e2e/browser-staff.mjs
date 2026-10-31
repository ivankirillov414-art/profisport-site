import assert from 'node:assert/strict';
import {Session} from './harness.mjs';
export async function verifyStaffBrowser(page,context,width,shot){
 await page.locator('[data-nav="settings"]').click();await page.getByRole('heading',{name:'Настройки',exact:true}).waitFor();
 assert.equal(await page.getByRole('link',{name:'Открыть инструмент резервного копирования'}).getAttribute('href'),new URL('maintenance.php',context.base_url).href);
 await page.getByRole('button',{name:'Добавить сотрудника',exact:true}).click();
 const dialog=page.locator('dialog'),email='browser'+width+'.'+context.id+'@qa.invalid';
 await dialog.locator('[name="name"]').fill('QA браузер сотрудник '+width);await dialog.locator('[name="email"]').fill(email);await dialog.locator('[name="password"]').fill(context.owner.password);await dialog.locator('[name="role"]').selectOption('reader');
 const submit=async(expected=200)=>{const response=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='users'&&r.request().method()==='POST');await dialog.getByRole('button',{name:'Сохранить',exact:true}).click();const r=await response,data=await r.json();assert.equal(r.status(),expected,data.error?.message);if(expected===200)await dialog.waitFor({state:'hidden'});return data;};
 const created=(await submit()).data;assert(created.id);const edit=async()=>{await page.locator('[data-user-id="'+created.id+'"]').click();await dialog.locator('[name="name"]').waitFor();};
 const session=new Session(context.base_url);await session.login(email,context.owner.password);
 await edit();await dialog.locator('[name="name"]').fill('QA изменён '+width);assert.equal(await dialog.locator('[name="password"]').inputValue(),'');await submit();assert.equal((await session.ok('auth/me')).user.name,'QA изменён '+width);
 await edit();await dialog.locator('[name="role"]').selectOption('editor');await submit();await session.request('partners',{expected:401});await session.login(email,context.owner.password);assert.equal((await session.ok('auth/me')).user.role,'editor');
 await edit();await dialog.locator('[name="active"]').uncheck();await submit();await session.request('partners',{expected:401});await page.locator('tr').filter({hasText:email}).getByText('Отключён',{exact:true}).waitFor();
 await page.locator('[data-user-id="'+context.owner_id+'"]').click();await dialog.locator('[name="active"]').uncheck();const rejected=await submit(409);assert.equal(rejected.error.code,'LAST_OWNER');await dialog.getByRole('alert').filter({hasText:'Нельзя отключить последнего владельца'}).waitFor();await dialog.getByRole('button',{name:'Отмена',exact:true}).click();await shot('settings');
 return {create_edit:true,blank_password_preserved:true,role_change_revokes:true,deactivation_revokes:true,last_owner_denied:true,owner_backup_link:true};
}
