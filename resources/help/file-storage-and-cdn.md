---
title: Store blog and campaign pictures on a CDN
summary: Serve pictures through a CDN, or keep them in a storage bucket such as Amazon S3 or Cloudflare R2, instead of only on this server.
order: 98
permission: manage_integrations
draft: true
---

By default, pictures uploaded to blog posts and campaign pages are stored on this server. You can make them load faster, and take the load off the server, in two ways. Both are in **Admin → Integrations → File storage & CDN**.

## Option 1: a CDN in front of this server

The pictures stay on this server, but visitors load them through your CDN, such as Cloudflare, BunnyCDN or Amazon CloudFront.

1. In your CDN, create a pull zone (or distribution) whose origin is this site.
2. Check that a picture opens through the CDN, for example `https://cdn.example.com/storage/blog/…`.
3. Open **CDN for files on this server**, enter the **CDN address** (ending in `/storage`) and switch it on.

## Option 2: a storage bucket

New pictures are uploaded to a bucket instead of this server. This works with Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi and other S3-compatible services.

1. Create a bucket, and an access key that can read, write and delete files in that bucket only.
2. Make the files readable by visitors: a public bucket address, a public bucket policy, or a CDN in front of the bucket.
3. Open **S3-compatible storage bucket** and fill in:
   - **Bucket name** and **Region**: use `auto` for Cloudflare R2;
   - **Endpoint**: leave it blank for Amazon S3;
   - **Access key ID** and **Secret access key**;
   - **Public or CDN address**: where visitors load the files.
4. Choose **Test connection**. A small test file is written to the bucket and deleted again.
5. Switch it on.

## Good to know

- Pictures already uploaded stay where they are and keep working. Only new uploads go to the new place.
- If you later switch the bucket off, leave the bucket online: pictures saved there are still loaded from it.
- While the bucket is on, the CDN option above is ignored.
- Keys are stored encrypted and are never shown again after saving.
