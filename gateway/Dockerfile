FROM nginx:alpine

COPY nginx.conf /etc/nginx/nginx.conf
COPY services /etc/nginx/services

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
