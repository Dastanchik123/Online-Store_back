const a=(r,s)=>{const t=r?.data?.errors;if(t&&typeof t=="object"){const e=Object.values(t).flat()[0];if(e)return String(e)}return r?.data?.message||s};export{a};
