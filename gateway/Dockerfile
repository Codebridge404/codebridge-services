FROM nginx:alpine

COPY nginx.conf /etc/nginx/nginx.conf
COPY services /etc/nginx/services
COPY docs.html /usr/share/nginx/html/docs.html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
